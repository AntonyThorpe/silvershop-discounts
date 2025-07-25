<?php

namespace SilverShop\Discounts\Tests;

use SilverShop\Discounts\Calculator;
use SilverStripe\Dev\SapphireTest;
use SilverShop\Tests\ShopTest;
use SilverStripe\Core\Config\Config;
use SilverShop\Discounts\Model\OrderCoupon;
use SilverShop\Page\Product;
use SilverShop\Model\Order;

class OrderCouponTest extends SapphireTest
{

    protected static $fixture_file = [
        'shop.yml'
    ];

    protected Order $cart;

    protected Order $unpaid;

    protected Order $othercart;

    protected Product $socks;

    protected Product $tshirt;

    protected Product $mp3player;

    protected function setUp(): void
    {
        parent::setUp();
        ShopTest::setConfiguration();

        Config::modify()->set(OrderCoupon::class, 'minimum_code_length', null);

        $this->socks = $this->objFromFixture(Product::class, 'socks');
        $this->socks->publishRecursive();

        $this->tshirt = $this->objFromFixture(Product::class, 'tshirt');
        $this->tshirt->publishRecursive();

        $this->mp3player = $this->objFromFixture(Product::class, 'mp3player');
        $this->mp3player->publishRecursive();

        $this->unpaid = $this->objFromFixture(Order::class, 'unpaid');
        $this->cart = $this->objFromFixture(Order::class, 'cart');
        $this->othercart = $this->objFromFixture(Order::class, 'othercart');
    }

    public function testMinimumLengthCode(): void
    {
        Config::modify()->set(OrderCoupon::class, 'minimum_code_length', 8);
        $coupon = OrderCoupon::create();
        $coupon->Code = '1234567';
        $result = $coupon->validate();
        $this->assertSame('INVALIDMINLENGTH', key($result->getMessages()));

        $coupon = OrderCoupon::create();
        $result = $coupon->validate();
        $this->assertNotContains('INVALIDMINLENGTH', $result->getMessages(), 'Leaving the Code field generates a code');

        $coupon = OrderCoupon::create(['Code' => '12345678']);
        $result = $coupon->validate();
        $this->assertNotContains('INVALIDMINLENGTH', $result->getMessages());

        Config::modify()->set(OrderCoupon::class, 'minimum_code_length', null);

        $coupon = OrderCoupon::create(['Code' => '1']);
        $result = $coupon->validate();
        $this->assertNotContains('INVALIDMINLENGTH', $result->getMessages());
    }

    public function testPercent(): void
    {
        $orderCoupon = OrderCoupon::create(
            [
                'Title' => '40% off each item',
                'Code' => '5B97AA9D75',
                'Type' => 'Percent',
                'Percent' => 0.40,
                'StartDate' => '2000-01-01 12:00:00',
                'EndDate' => '2200-01-01 12:00:00'
            ]
        );
        $orderCoupon->write();

        $context = ['CouponCode' => $orderCoupon->Code];
        $this->assertTrue($orderCoupon->validateOrder($this->cart, $context), (string)$orderCoupon->getMessage());
        $this->assertEqualsWithDelta(4, (int) $orderCoupon->getDiscountValue(10), PHP_FLOAT_EPSILON);
        $this->assertSame(200, (int) $this->calc($this->unpaid, $orderCoupon), '40% off order');
    }

    public function testAmount(): void
    {
        $orderCoupon = OrderCoupon::create(
            [
                'Title' => '$10 off each item',
                'Code' => 'TENDOLLARSOFF',
                'Type' => 'Amount',
                'Amount' => 10,
                'Active' => 1
            ]
        );
        $orderCoupon->write();

        $context = ['CouponCode' => $orderCoupon->Code];
        $this->assertTrue($orderCoupon->validateOrder($this->cart, $context), (string)$orderCoupon->getMessage());
        $this->assertSame(10, (int) $orderCoupon->getDiscountValue(1000), '$10 off fixed value');
        $this->assertTrue($orderCoupon->validateOrder($this->unpaid, $context), (string)$orderCoupon->getMessage());
        $this->assertEqualsWithDelta(60, (int) $this->calc($this->unpaid, $orderCoupon), PHP_FLOAT_EPSILON);
        //TODO: test amount that is greater than item value
    }

    public function testInactiveCoupon(): void
    {
        $orderCoupon = OrderCoupon::create(
            [
                'Title' => 'Not active',
                'Code' => 'EE891574D6',
                'Type' => 'Amount',
                'Amount' => 10,
                'Active' => 0
            ]
        );
        $orderCoupon->write();

        $context = ['CouponCode' => $orderCoupon->Code];
        $this->assertFalse($orderCoupon->validateOrder($this->cart, $context), 'Coupon is not set to active');
    }

    protected function getCalculator(Order $order, OrderCoupon $orderCoupon): Calculator
    {
        return Calculator::create($order, ['CouponCode' => $orderCoupon->Code]);
    }

    protected function calc(Order $order, OrderCoupon $orderCoupon): int|float
    {
        return $this->getCalculator($order, $orderCoupon)->calculate();
    }
}
