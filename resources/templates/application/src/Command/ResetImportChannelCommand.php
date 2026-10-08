<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Customer\Customer;
use App\Entity\Order\Order;
use App\Entity\Product\Product;
use App\Entity\Taxonomy\Taxon;
use App\Entity\User\AdminUser;
use App\Entity\User\ShopUser;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sylius:import:channel:reset',
    description: 'Remove one imported shop channel and its catalog so fixtures can load again',
)]
final class ResetImportChannelCommand extends Command
{
    /**
     * @param ChannelRepositoryInterface<ChannelInterface>                 $channelRepository
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface>     $paymentMethodRepository
     * @param ShippingMethodRepositoryInterface<ShippingMethodInterface>   $shippingMethodRepository
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Channel code (e.g. COCORICO)')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Product and taxon code prefix (defaults to the lowercased channel code)')
            ->addOption('shop-email', null, InputOption::VALUE_REQUIRED, 'Imported shop user email to remove')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = strtoupper(trim((string) $input->getArgument('code')));
        $prefix = strtolower(trim((string) ($input->getOption('prefix') ?? '')));

        if ('' === $prefix) {
            $prefix = strtolower($code);
        }

        $prefix = rtrim($prefix, '_');
        $shopEmail = trim((string) ($input->getOption('shop-email') ?? ''));

        /** @var ChannelInterface|null $channel */
        $channel = $this->channelRepository->findOneBy(['code' => $code]);

        if (null === $channel) {
            $io->comment(\sprintf(
                'Channel %s does not exist — cleaning orphaned catalog (prefix %s).',
                $code,
                $prefix,
            ));

            $this->removeImportAdminUsers($code);
            $this->removeImportShopUser($shopEmail);
            $this->removeProductsByPrefix($prefix);
            $this->removeTaxons($prefix);
            $this->entityManager->flush();

            $io->success(\sprintf('Orphaned catalog cleaned for prefix %s.', $prefix));

            return Command::SUCCESS;
        }

        $io->comment(\sprintf('Resetting channel %s (prefix %s).', $code, $prefix));

        $this->removeImportAdminUsers($code);
        $this->removeImportShopUser($shopEmail);
        $this->removeOrders($channel);
        $this->removeProducts($channel, $prefix);
        $this->detachPaymentAndShipping($channel);
        $channel->setMenuTaxon(null);
        $this->entityManager->flush();

        $this->entityManager->remove($channel);
        $this->entityManager->flush();

        $this->removeTaxons($prefix);
        $this->entityManager->flush();

        $io->success(\sprintf('Channel %s reset.', $code));

        return Command::SUCCESS;
    }

    private function removeImportAdminUsers(string $channelCode): void
    {
        $adminUsers = $this->entityManager->createQueryBuilder()
            ->select('adminUser')
            ->from(AdminUser::class, 'adminUser')
            ->andWhere('adminUser.channelCode = :channelCode')
            ->setParameter('channelCode', $channelCode)
            ->getQuery()
            ->getResult()
        ;

        foreach ($adminUsers as $adminUser) {
            $this->entityManager->remove($adminUser);
        }
    }

    private function removeImportShopUser(string $shopEmail): void
    {
        if ('' === $shopEmail) {
            return;
        }

        /** @var ShopUser|null $shopUser */
        $shopUser = $this->entityManager->createQueryBuilder()
            ->select('shopUser', 'customer')
            ->from(ShopUser::class, 'shopUser')
            ->innerJoin('shopUser.customer', 'customer')
            ->andWhere('customer.email = :email')
            ->setParameter('email', $shopEmail)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        if (null === $shopUser) {
            return;
        }

        $customer = $shopUser->getCustomer();

        $this->entityManager->remove($shopUser);

        if ($customer instanceof Customer) {
            $this->entityManager->remove($customer);
        }
    }

    private function removeOrders(ChannelInterface $channel): void
    {
        $orders = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->andWhere('o.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getResult()
        ;

        foreach ($orders as $order) {
            $this->entityManager->remove($order);
        }
    }

    private function removeProducts(ChannelInterface $channel, string $prefix): void
    {
        $products = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->distinct()
            ->from(Product::class, 'p')
            ->leftJoin('p.channels', 'channel')
            ->andWhere('channel = :channel OR p.code LIKE :like')
            ->setParameter('channel', $channel)
            ->setParameter('like', $prefix . '_%')
            ->getQuery()
            ->getResult()
        ;

        foreach ($products as $product) {
            $this->entityManager->remove($product);
        }
    }

    private function removeProductsByPrefix(string $prefix): void
    {
        $products = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('p.code LIKE :like')
            ->setParameter('like', $prefix . '_%')
            ->getQuery()
            ->getResult()
        ;

        foreach ($products as $product) {
            $this->entityManager->remove($product);
        }
    }

    private function detachPaymentAndShipping(ChannelInterface $channel): void
    {
        foreach ($this->paymentMethodRepository->findAll() as $method) {
            if ($method->hasChannel($channel)) {
                $method->removeChannel($channel);
            }
        }

        foreach ($this->shippingMethodRepository->findAll() as $method) {
            if ($method->hasChannel($channel)) {
                $method->removeChannel($channel);
            }
        }
    }

    private function removeTaxons(string $prefix): void
    {
        $taxons = $this->entityManager->createQueryBuilder()
            ->select('taxon')
            ->from(Taxon::class, 'taxon')
            ->andWhere('taxon.code = :root OR taxon.code LIKE :like')
            ->setParameter('root', $prefix . '_category')
            ->setParameter('like', $prefix . '_%')
            ->addOrderBy('taxon.level', 'DESC')
            ->getQuery()
            ->getResult()
        ;

        foreach ($taxons as $taxon) {
            $this->entityManager->remove($taxon);
        }
    }
}
