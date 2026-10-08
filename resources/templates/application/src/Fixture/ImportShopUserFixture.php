<?php

declare(strict_types=1);

namespace App\Fixture;

use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImportShopUserFixture extends AbstractFixture
{
    private OptionsResolver $optionsResolver;

    /**
     * @param FactoryInterface<ShopUserInterface> $shopUserFactory
     * @param FactoryInterface<CustomerInterface> $customerFactory
     * @param UserRepositoryInterface<ShopUserInterface> $shopUserRepository
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.shop_user')]
        private readonly FactoryInterface $shopUserFactory,
        #[Autowire(service: 'sylius.factory.customer')]
        private readonly FactoryInterface $customerFactory,
        #[Autowire(service: 'sylius.repository.shop_user')]
        private readonly UserRepositoryInterface $shopUserRepository,
    ) {
        $this->optionsResolver = (new OptionsResolver())
            ->setDefault('custom', [])
            ->setAllowedTypes('custom', 'array');
    }

    public function getName(): string
    {
        return 'import_shop_user';
    }

    public function load(array $options): void
    {
        $options = $this->optionsResolver->resolve($options);

        foreach ($options['custom'] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $email = trim((string) ($item['email'] ?? ''));
            $password = trim((string) ($item['password'] ?? ''));

            if ('' === $email || '' === $password) {
                continue;
            }

            /** @var ShopUserInterface|null $shopUser */
            $shopUser = $this->shopUserRepository->findOneByEmail($email);

            if (null === $shopUser) {
                /** @var CustomerInterface $customer */
                $customer = $this->customerFactory->createNew();
                $customer->setEmail($email);
                $customer->setFirstName(trim((string) ($item['first_name'] ?? 'Customer')));
                $customer->setLastName(trim((string) ($item['last_name'] ?? '')));
                $customer->setGender(CustomerInterface::UNKNOWN_GENDER);

                /** @var ShopUserInterface $shopUser */
                $shopUser = $this->shopUserFactory->createNew();
                $shopUser->setCustomer($customer);
                $shopUser->addRole('ROLE_USER');
            } else {
                $customer = $shopUser->getCustomer();

                if (null !== $customer) {
                    $customer->setFirstName(trim((string) ($item['first_name'] ?? $customer->getFirstName() ?? 'Customer')));
                    $customer->setLastName(trim((string) ($item['last_name'] ?? $customer->getLastName() ?? '')));
                }
            }

            $shopUser->setPlainPassword($password);
            $shopUser->setEnabled(true);

            $this->shopUserRepository->add($shopUser);
        }
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('email')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('password')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('first_name')->defaultNull()->end()
                            ->scalarNode('last_name')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
