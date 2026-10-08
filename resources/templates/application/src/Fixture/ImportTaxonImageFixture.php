<?php

declare(strict_types=1);

namespace App\Fixture;

use App\Entity\Taxonomy\TaxonImage;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Model\TaxonImageInterface;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImportTaxonImageFixture extends AbstractFixture
{
    private OptionsResolver $optionsResolver;

    /**
     * @param FactoryInterface<TaxonImageInterface> $taxonImageFactory
     * @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly FactoryInterface $taxonImageFactory,
        private readonly TaxonRepositoryInterface $taxonRepository,
        private readonly ImageUploaderInterface $imageUploader,
        private readonly ParameterBagInterface $parameterBag,
    ) {
        $this->optionsResolver = (new OptionsResolver())
            ->setDefault('custom', [])
            ->setAllowedTypes('custom', 'array');
    }

    public function getName(): string
    {
        return 'import_taxon_image';
    }

    public function load(array $options): void
    {
        $options = $this->optionsResolver->resolve($options);

        foreach ($options['custom'] as $item) {
            $this->loadTaxonImage($item);
        }

        $this->getObjectManager()->flush();
        $this->getObjectManager()->clear();
    }

    private function getObjectManager(): ObjectManager
    {
        $manager = $this->managerRegistry->getManagerForClass(TaxonImage::class);

        if (!$manager instanceof ObjectManager) {
            throw new \RuntimeException('Could not resolve Doctrine manager for TaxonImage.');
        }

        return $manager;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function loadTaxonImage(array $item): void
    {
        $taxonCode = trim((string) ($item['taxon'] ?? ''));

        if ('' === $taxonCode) {
            return;
        }

        /** @var TaxonInterface|null $taxon */
        $taxon = $this->taxonRepository->findOneBy(['code' => $taxonCode]);

        if (null === $taxon) {
            return;
        }

        $type = trim((string) ($item['type'] ?? 'main'));

        if ('' === $type) {
            $type = 'main';
        }

        if (!$taxon->getImagesByType($type)->isEmpty()) {
            return;
        }

        $path = $this->resolvePath(trim((string) ($item['path'] ?? '')));

        if ('' === $path || !is_readable($path)) {
            return;
        }

        $uploadedFile = new UploadedFile($path, basename($path), test: true);

        /** @var TaxonImageInterface $taxonImage */
        $taxonImage = $this->taxonImageFactory->createNew();
        $taxonImage->setFile($uploadedFile);
        $taxonImage->setType($type);

        $this->imageUploader->upload($taxonImage);
        $taxon->addImage($taxonImage);
        $this->getObjectManager()->persist($taxonImage);
    }

    private function resolvePath(string $path): string
    {
        if ('' === $path) {
            return '';
        }

        if (str_starts_with($path, '%') && str_contains($path, '%/')) {
            return (string) $this->parameterBag->resolveValue($path);
        }

        return $path;
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('taxon')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('type')->defaultValue('main')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
