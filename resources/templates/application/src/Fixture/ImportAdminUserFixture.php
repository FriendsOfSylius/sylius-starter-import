<?php

declare(strict_types=1);

namespace App\Fixture;

use App\Entity\User\AdminUser;
use App\Entity\User\ImportChannelAdminAwareInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImportAdminUserFixture extends AbstractFixture
{
    public const ROLE_IMPORT_CHANNEL_ADMIN = 'ROLE_IMPORT_CHANNEL_ADMIN';

    private OptionsResolver $optionsResolver;

    /**
     * @param FactoryInterface<AdminUser> $adminUserFactory
     * @param UserRepositoryInterface<AdminUser> $adminUserRepository
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.admin_user')]
        private readonly FactoryInterface $adminUserFactory,
        #[Autowire(service: 'sylius.repository.admin_user')]
        private readonly UserRepositoryInterface $adminUserRepository,
    ) {
        $this->optionsResolver = (new OptionsResolver())
            ->setDefault('custom', [])
            ->setAllowedTypes('custom', 'array');
    }

    public function getName(): string
    {
        return 'import_admin_user';
    }

    public function load(array $options): void
    {
        $options = $this->optionsResolver->resolve($options);

        foreach ($options['custom'] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $channelCode = trim((string) ($item['channel'] ?? ''));
            $username = trim((string) ($item['username'] ?? ''));
            $password = trim((string) ($item['password'] ?? ''));

            if ('' === $channelCode || '' === $username || '' === $password) {
                continue;
            }

            /** @var AdminUser|null $adminUser */
            $adminUser = $this->adminUserRepository->findOneBy(['channelCode' => $channelCode]);

            if (null === $adminUser) {
                $adminUser = $this->adminUserRepository->findOneBy(['username' => $username]);
            }

            if (null === $adminUser) {
                /** @var AdminUser $adminUser */
                $adminUser = $this->adminUserFactory->createNew();
            }

            $adminUser->setUsername($username);
            $adminUser->setEmail(trim((string) ($item['email'] ?? $username . '@import.local')));
            $adminUser->setPlainPassword($password);
            $adminUser->setEnabled(true);
            $adminUser->setLocaleCode('en_US');
            $adminUser->setFirstName(trim((string) ($item['first_name'] ?? $username)));
            $adminUser->setLastName('Admin');

            if ($adminUser instanceof ImportChannelAdminAwareInterface) {
                $adminUser->setChannelCode($channelCode);
                $adminUser->setImportCodePrefix(trim((string) ($item['import_code_prefix'] ?? '')));
            }

            if (!$adminUser->hasRole('ROLE_ADMINISTRATION_ACCESS')) {
                $adminUser->addRole('ROLE_ADMINISTRATION_ACCESS');
            }

            if (!$adminUser->hasRole(self::ROLE_IMPORT_CHANNEL_ADMIN)) {
                $adminUser->addRole(self::ROLE_IMPORT_CHANNEL_ADMIN);
            }

            $this->adminUserRepository->add($adminUser);
        }
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('channel')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('username')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('password')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('email')->defaultNull()->end()
                            ->scalarNode('first_name')->defaultNull()->end()
                            ->scalarNode('import_code_prefix')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
