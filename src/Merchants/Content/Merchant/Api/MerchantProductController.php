<?php declare(strict_types=1);

namespace Shopware\Production\Merchants\Content\Merchant\Api;

use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Production\Merchants\Content\Merchant\MerchantEntity;
use Shopware\Production\Merchants\Content\Merchant\SalesChannelContextExtension;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class MerchantProductController
{
    public const PRODUCT_TYPES = [
        'product',
        'voucher',
        'service',
        'storeWindow'
    ];

    /**
     * @var EntityRepositoryInterface
     */
    private $productRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $taxRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $merchantRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $productMediaRepository;

    /**
     * @var MediaService
     */
    private $mediaService;

    /**
     * @var NumberRangeValueGeneratorInterface
     */
    private $numberRangeValueGenerator;

    public function __construct(
        EntityRepositoryInterface $productRepository,
        EntityRepositoryInterface $taxRepository,
        EntityRepositoryInterface $mediaRepository,
        EntityRepositoryInterface $merchantRepository,
        EntityRepositoryInterface $productMediaRepository,
        NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        MediaService $mediaService
    ) {
        $this->productRepository = $productRepository;
        $this->taxRepository = $taxRepository;
        $this->mediaRepository = $mediaRepository;
        $this->merchantRepository = $merchantRepository;
        $this->productMediaRepository = $productMediaRepository;
        $this->numberRangeValueGenerator = $numberRangeValueGenerator;
        $this->mediaService = $mediaService;
    }

    /**
     * @Route(name="merchant-api.merchant.product.read", path="/merchant-api/v{version}/products", methods={"GET"})
     */
    public function getList(Request $request, SalesChannelContext $context): JsonResponse
    {
        $merchant = SalesChannelContextExtension::extract($context);

        $criteria = new Criteria();
        $criteria->addAssociation('merchants');
        $criteria->addFilter(new EqualsFilter('merchants.id', $merchant->getId()));

        // Fetch total before limit etc. is applied
        $total = $this->productRepository->search($criteria, Context::createDefaultContext())->getTotal();

        $criteria->addAssociation('media');

        if ($request->query->has('limit')) {
            $criteria->setLimit((int) $request->query->get('limit'));
        }

        if ($request->query->has('offset')) {
            $criteria->setOffset((int) $request->query->get('offset'));
        }

        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $products = $this->productRepository->search($criteria, Context::createDefaultContext());

        $productsArray = [];
        /** @var ProductEntity $product */
        foreach ($products as $key => $product) {
            $productData = [
                'id' => $product->getId(),
                'name' => $product->getTranslation('name'),
                'productNumber' => $product->getProductNumber(),
                'stock' => $product->getStock(),
                'description' => $product->getTranslation('description'),
                'price' => $product->getPrice()->first()->getGross(),
                'tax' => $product->getTax()->getTaxRate(),
                'active' => $product->getActive(),
                'productType' => $product->getCustomFields()['productType']
            ];

            if ($product->getMedia()->count() > 0) {
                foreach ($product->getMedia() as $media) {
                    $productData['media'][] = $media->getMedia()->getUrl();
                }
            }

            $productsArray[] = $productData;
        }

        return new JsonResponse([
            'data' => $productsArray,
            'total' => $total
        ]);
    }

    /**
     * @Route(name="merchant-api.merchant.product.read-detail", path="/merchant-api/v{version}/products/{productId}", methods={"GET"})
     */
    public function detailProduct(string $productId, SalesChannelContext $context): JsonResponse
    {
        $merchant = SalesChannelContextExtension::extract($context);

        return new JsonResponse(
            [
                'data' => $this->fetchProductData($productId, $merchant)
            ]
        );
    }

    /**
     * @Route(name="merchant-api.merchant.product.create", path="/merchant-api/v{version}/products", methods={"POST"}, defaults={"csrf_protected"=false})
     */
    public function create(Request $request, SalesChannelContext $context): JsonResponse
    {
        $merchant = SalesChannelContextExtension::extract($context);

        $missingFields = $this->checkForMissingFields($request);

        if ($missingFields) {
            throw new \InvalidArgumentException('The following missing values must be set: ' . implode(', ', $missingFields));
        }

        $this->validateProductType($request->request->get('productType'));

        $productNumber = $request->request->get('productNumber', $this->numberRangeValueGenerator->getValue('product', $context->getContext(), $context->getSalesChannel()->getId()));

        $productData = [
            'id' => Uuid::randomHex(),
            'name' => $request->request->get('name'),
            'description' => $request->request->get('description'),
            'stock' => $request->request->getInt('stock', 0),
            'productNumber' => $productNumber,
            'price' => [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => $request->request->get('price'),
                    'net' => $request->request->get('price') / (1 + $request->request->get('tax') / 100),
                    'linked' => true
                ]
            ],
            'visibilities' => [
                [
                    'salesChannelId' => $merchant->getSalesChannelId(),
                    'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL
                ],
            ],
            'merchants' => [
                [
                    'id' => $merchant->getId()
                ]
            ],
            'active' => (bool) $request->request->get('active', true),
            'customFields' => [
                'productType' => $request->request->get('productType')
            ]
        ];

        $taxEntity = $this->getTaxFromRequest($request, $context);
        $productData['tax'] = ['id' => $taxEntity->getId()];

        $productData = $this->checkForMedias($request, $context, $productData);

        // Write in default language (otherwise we get an exception)
        $this->productRepository->create([$productData], Context::createDefaultContext());

        // customFields are not inherited from translations. So we need to write them in active sales channel language
        $this->productRepository->update([
            [
                'id' => $productData['id'],
                'customFields' => [
                    'productType' => $request->request->get('productType')
                ]
            ]
        ], $context->getContext());

        return new JsonResponse(
            ['message' => 'Successfully created product!', 'data' => $this->fetchProductData($productData['id'], $merchant)]
        );
    }

    /**
     * @Route(name="merchant-api.merchant.product.update", path="/merchant-api/v{version}/products/{productId}", methods={"POST"}, defaults={"csrf_protected"=false})
     */
    public function update(Request $request, string $productId, SalesChannelContext $context): JsonResponse
    {
        $merchant = SalesChannelContextExtension::extract($context);

        $product = $this->getProductFromMerchant($productId, $context);

        if (!$product) {
            throw new NotFoundHttpException(sprintf('Cannot find product by id %s', $productId));
        }

        $productData = [];
        if ($request->request->has('name')) {
            $productData['name'] = $request->request->get('name');
        }

        if ($request->request->has('description')) {
            $productData['description'] = $request->request->get('description');
        }

        if ($request->request->has('active')) {
            $productData['active'] = (bool) $request->request->get('active');
        }

        if ($request->request->has('tax')) {
            $taxEntity = $this->getTaxFromRequest($request, $context);
            $productData['tax'] = ['id' => $taxEntity->getId()];
            $product->setTax($taxEntity);
        }

        if ($request->request->has('stock')) {
            $productData['stock'] = $request->request->getInt('stock', 0);
        }

        if ($request->request->has('price')) {
            $productData['price'] = [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => $request->request->get('price'),
                    'net' => $request->request->get('price') / (1 + $product->getTax()->getTaxRate()/100),
                    'linked' => true
                ]
            ];
        }

        if ($request->request->has('productType')) {
            $this->validateProductType($request->request->get('productType'));
            $productData['customFields'] = ['productType' => $request->request->get('productType')];
        }

        $this->deletePreviousMedias($request, $productId);
        $productData = $this->checkForMedias($request, $context, $productData);

        if (!$productData) {
            throw new \InvalidArgumentException('No update data was provided.');
        }

        $productData['id'] = $productId;

        $this->productRepository->update([$productData], Context::createDefaultContext());

        return new JsonResponse(
            ['message' => 'Successfully saved product!', 'data' => $this->fetchProductData($productId, $merchant)]
        );
    }

    private function getProductFromMerchant(string $productId, SalesChannelContext $context): ProductEntity
    {
        $product = $this->getMerchantFromContext($context)->getProducts()->get($productId);

        if (!$product) {
            throw new NotFoundHttpException(sprintf('Cannot find product by id %s for current merchant.', $productId));
        }

        return $product;
    }

    private function createMediaIdByFile(UploadedFile $uploadedFile, SalesChannelContext $context): string
    {
        $mediaId = Uuid::randomHex();

        $this->mediaRepository->create([['id' => $mediaId]], $context->getContext());

        $mediaFile = new MediaFile(
            $uploadedFile->getPathname(),
            $uploadedFile->getMimeType(),
            'jpg',
            $uploadedFile->getSize()
        );

        $this->mediaService->saveMediaFile($mediaFile, md5(random_bytes(10)), $context->getContext(), null, $mediaId);

        return $mediaId;
    }

    private function getTaxFromRequest(Request $request, SalesChannelContext $context): TaxEntity
    {
        $taxCriteria = new Criteria();
        $taxCriteria->addFilter(new EqualsFilter('taxRate', $request->request->get('tax')));
        /** @var TaxEntity $taxEntity */
        $taxEntity = $this->taxRepository->search($taxCriteria, $context->getContext())->first();

        if (!$taxEntity) {
            throw new NotFoundHttpException(sprintf('Cannot find tax by rate %s', $request->request->get('tax')));
        }

        return $taxEntity;
    }

    private function checkForMissingFields(Request $request): array
    {
        $necessaryFields = [
            'name',
            'description',
            'tax',
            'price',
            'productType'
        ];

        $missingFields = [];
        foreach ($necessaryFields as $necessaryField) {
            if ($request->request->has($necessaryField)) {
                continue;
            }

            $missingFields[] = $necessaryField;
        }

        return $missingFields;
    }

    private function validateProductType(string $productType): void
    {
        if (in_array($productType, self::PRODUCT_TYPES)) {
            return;
        }

        throw new \InvalidArgumentException('The product type ' . $productType . ' is not valid. One values must these must be set: ' . implode(', ', self::PRODUCT_TYPES));
    }

    private function getMerchantFromContext(SalesChannelContext $context): MerchantEntity
    {
        $customerId = $context->getCustomer()->getId();
        $criteria = new Criteria();
        $criteria->addAssociation('products');
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        return $this->merchantRepository->search($criteria, $context->getContext())->first();
    }

    private function checkForMedias(Request $request, SalesChannelContext $context, array $productData): array
    {
        if (!$request->files->has('media')) {
            return $productData;
        }

        $mediaIds = [];
        foreach ($request->files->get('media') as $uploadedFile) {
            $mediaIds[] = $this->createMediaIdByFile($uploadedFile, $context);
        }

        $productData['cover'] = ['mediaId' => $mediaIds[0]];
        unset($mediaIds[0]);

        foreach ($mediaIds as $mediaId) {
            $productData['media'][] = [
                'mediaId' => $mediaId
            ];
        }

        return $productData;
    }

    private function deletePreviousMedias(Request $request, string $productId): void
    {
        if (!$request->files->has('media')) {
            return;
        }

        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('media');
        /** @var ProductEntity $product */
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

        $mediaIds = [];

        foreach ($product->getMedia() as $productMediaId => $media) {
            $mediaIds[] = ['id' => $productMediaId];
        }

        if (empty($mediaIds)) {
            return;
        }

        $this->productMediaRepository->delete($mediaIds, Context::createDefaultContext());
    }

    private function fetchProductData(string $productId, MerchantEntity $merchant): array
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('merchants');
        $criteria->addFilter(new EqualsFilter('merchants.id', $merchant->getId()));
        $criteria->addAssociation('media');

        /** @var ProductEntity $product */
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();
        $productData = [
            'id' => $product->getId(),
            'name' => $product->getTranslation('name'),
            'productNumber' => $product->getProductNumber(),
            'stock' => $product->getStock(),
            'description' => $product->getTranslation('description'),
            'price' => $product->getPrice()->first()->getGross(),
            'tax' => $product->getTax()->getTaxRate(),
            'active' => $product->getActive(),
            'productType' => $product->getCustomFields()['productType']
        ];

        if ($product->getMedia()->count() > 0) {
            foreach ($product->getMedia() as $media) {
                $productData['media'][] = $media->getMedia()->getUrl();
            }
        }

        return $productData;
    }
}
