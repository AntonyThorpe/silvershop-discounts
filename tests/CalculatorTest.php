<?php

namespace SilverShop\Discounts\Tests;

use SilverShop\Discounts\Calculator;
use SilverShop\Discounts\Adjustment;
use SilverShop\Discounts\PriceInfo;
use SilverStripe\Dev\SapphireTest;
use SilverShop\Tests\ShopTest;
use SilverShop\Model\Order;
use SilverShop\Checkout\OrderProcessor;
use SilverShop\Discounts\Model\Modifiers\OrderDiscountModifier;
use SilverShop\Discounts\Model\OrderDiscount;
use SilverShop\Discounts\Model\Discount;
use SilverShop\Discounts\Model\OrderCoupon;
use SilverShop\Page\Product;

class CalculatorTest extends SapphireTest
{
    protected static $fixture_file = [
        'Discounts.yml',
        'shop.yml'
    ];

    protected Order $cart;

    protected Order $emptycart;

    protected Order $megacart;

    protected Order $modifiedcart;

    protected Order $othercart;

    protected Product $socks;

    protected Product $tshirt;

    protected Product $mp3player;

    protected function setUp(): void
    {
        parent::setUp();
        ShopTest::setConfiguration();

        Order::config()->modifiers = [
            OrderDiscountModifier::class
        ];

        $this->socks = $this->objFromFixture(Product::class, 'socks');
        $this->socks->publishRecursive();

        $this->tshirt = $this->objFromFixture(Product::class, 'tshirt');
        $this->tshirt->publishRecursive();

        $this->mp3player = $this->objFromFixture(Product::class, 'mp3player');
        $this->mp3player->publishRecursive();

        $this->cart = $this->objFromFixture(Order::class, 'cart');
        $this->othercart = $this->objFromFixture(Order::class, 'othercart');
        $this->megacart = $this->objFromFixture(Order::class, 'megacart');
        $this->emptycart = $this->objFromFixture(Order::class, 'emptycart');
        $this->modifiedcart = $this->objFromFixture(Order::class, 'modifiedcart');
    }

    public function testAdjustment(): void
    {
        $adjustment1 = new Adjustment(10, null);
        $adjustment2 = new Adjustment(5, null);
        $this->assertSame(10, $adjustment1->getValue());
        $this->assertEquals($adjustment1, Adjustment::better_of($adjustment1, $adjustment2));
    }

    public function testPriceInfo(): void
    {
        $priceInfo = new PriceInfo(20);
        $this->assertSame(20, $priceInfo->getPrice());
        $this->assertSame(20, $priceInfo->getOriginalPrice());
        $this->assertSame(0, $priceInfo->getCompoundedDiscount());
        $this->assertSame(0, $priceInfo->getBestDiscount());
        $this->assertSame([], $priceInfo->getAdjustments());

        $priceInfo->adjustPrice($a1 = new Adjustment(1, 'a'));
        $priceInfo->adjustPrice($a2 = new Adjustment(5, 'b'));
        $priceInfo->adjustPrice($a3 = new Adjustment(2, 'c'));

        $this->assertSame(12, $priceInfo->getPrice());
        $this->assertSame(20, $priceInfo->getOriginalPrice());
        $this->assertSame(8, $priceInfo->getCompoundedDiscount());
        $this->assertSame(5, $priceInfo->getBestDiscount());
        $this->assertEquals([$a1,$a2,$a3], $priceInfo->getAdjustments());
    }

    public function testBasicItemDiscount(): void
    {
        //activate discounts
        $orderDiscount = OrderDiscount::create(
            [
            'Title' => '10% off',
            'Type' => 'Percent',
            'Percent' => 0.1
            ]
        );
        $orderDiscount->write();
        //check that discount works as expected
        $this->assertSame(1, (int) $orderDiscount->getDiscountValue(10), '10% of 10 is 1');
        //check that discount matches order
        $arrayList = Discount::get_matching($this->cart);
        $this->assertListEquals(
            [
                ['Title' => '10% off']
            ],
            $arrayList
        );
        //check valid
        $valid = $orderDiscount->validateOrder($this->cart);
        $this->assertTrue($valid, 'discount is valid');
        //check calculator
        $calculator = Calculator::create($this->cart);
        $this->assertEqualsWithDelta(0.8, $calculator->calculate(), PHP_FLOAT_EPSILON);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testZeroOrderDiscount(): void
    {
        OrderDiscount::create(
            [
                'Title' => 'Everything is free!',
                'Type' => 'Percent',
                'Percent' => 1,
                'ForItems' => 1,
                'ForCart' => 1,
                'ForShipping' => 1
            ]
        )->write();
        $this->markTestIncomplete('Add assertions');
    }

    public function testItemLevelPercentAndAmountDiscounts(): void
    {
        OrderDiscount::get()->removeAll();
        OrderDiscount::create(
            [
                'Title' => '10% off',
                'Type' => 'Percent',
                'Percent' => 0.10
            ]
        )->write();

        OrderDiscount::create(
            [
                'Title' => '$5 off',
                'Type' => 'Amount',
                'Amount' => 5
            ]
        )->write();

        //check that discount matches order
        $arrayList = Discount::get_matching($this->cart);
        $this->assertListEquals(
            [
                ['Title' => '10% off'],
                ['Title' => '$5 off']
            ],
            $arrayList
        );

        $calculator = Calculator::create($this->emptycart);
        $this->assertSame(0, (int) $calculator->calculate(), 'nothing in cart');
        //check that best discount was chosen
        $calculator = Calculator::create($this->cart);
        $this->assertSame(5, (int) $calculator->calculate(), '$5 off $8 is best discount');

        $calculator = Calculator::create($this->othercart);
        $this->assertSame(20, (int) $calculator->calculate(), '10% off $400 is best discount');
        //total discount calculation
        //20 * socks($8) = 160 ...$5 off each = 100
        //10 * tshirt($25) = 250 ..$5 off each  = 50
        //2 * mp3player($200) = 400 ..10% off each = 40
        //total discount: 190
        $calculator = Calculator::create($this->megacart);
        $this->assertSame(190, (int) $calculator->calculate(), 'complex savings example');

        $this->assertListEquals(
            [
                ['Title' => '10% off'],
                ['Title' => '$5 off']
            ],
            $this->megacart->Discounts()
        );
    }

    public function testCouponAndDiscountItemLevel(): void
    {
        OrderDiscount::create(
            [
            'Title' => '10% off',
            'Type' => 'Percent',
            'Percent' => 0.10
            ]
        )->write();
        OrderCoupon::create(
            [
                'Title' => '$10 off each item',
                'Code' => 'TENDOLLARSOFF',
                'Type' => 'Amount',
                'Amount' => 10
            ]
        )->write();

        //total discount calculation
        //20 * socks($8) = 160 ...$10 off each ($8max) = 160
        //10 * tshirt($25) = 250 ..$10 off each  = 100
        //2 * mp3player($200) = 400 ..10% off each = 40
        //total discount: 300
        $calculator = Calculator::create(
            $this->megacart,
            [
                'CouponCode' => 'TENDOLLARSOFF'
            ]
        );
        $this->assertSame(300, (int) $calculator->calculate(), 'complex savings example');
        //no coupon in context
        $calculator = Calculator::create($this->megacart);
        $this->assertSame(81, (int) $calculator->calculate(), 'complex savings example');
        //write a test that combines discounts which sum to a greater discount than
        //the order subtotal
    }

    public function testItemAndCartLevelAmountDiscounts(): void
    {
        OrderDiscount::create(
            [
                'Title' => '$400 savings',
                'Type' => 'Amount',
                'Amount' => 400,
                'ForItems' => false,
                'ForCart' => true
            ]
        )->write();

        OrderDiscount::create(
            [
                'Title' => '$500 off baby!',
                'Type' => 'Amount',
                'Amount' => 500,
                'ForItems' => true,
                'ForCart' => false
            ]
        )->write();

        $calculator = Calculator::create($this->megacart);
        $this->assertSame(810, (int) $calculator->calculate(), "total shouldn't exceed what is possible");

        $this->markTestIncomplete('test distribution of amounts');
    }

    public function testCartLevelAmount(): void
    {
        //entire cart
        $orderDiscount = OrderDiscount::create(
            [
                'Title' => '$25 off cart total',
                'Type' => 'Amount',
                'Amount' => 25,
                'ForItems' => false,
                'ForCart' => true
            ]
        );
        $orderDiscount->write();
        $this->assertTrue($orderDiscount->validateOrder($this->cart));
        $calculator = Calculator::create($this->cart);
        $this->assertSame(8, (int) $calculator->calculate());
        $calculator = Calculator::create($this->othercart);
        $this->assertSame(25, (int) $calculator->calculate());
        $calculator = Calculator::create($this->megacart);
        $this->assertSame(25, (int) $calculator->calculate());
    }

    public function testCartLevelPercent(): void
    {
        $orderDiscount = OrderDiscount::create(
            [
                'Title' => '50% off products subtotal',
                'Type' => 'Percent',
                'Percent' => 0.5,
                'ForItems' => false,
                'ForCart' => true
            ]
        );
        $orderDiscount->write();

        //products subtotal
        $orderDiscount->Products()->addMany(
            [
                $this->socks,
                $this->tshirt
            ]
        );
        $calculator = Calculator::create($this->cart);
        $this->assertSame(4, (int) $calculator->calculate());
        $calculator = Calculator::create($this->megacart);
        $this->assertSame(205, (int) $calculator->calculate());
    }

    public function testMaxAmount(): void
    {
        //percent item discounts
        $discount = OrderDiscount::create(
            [
                'Title' => '$200 max Discount',
                'Type' => 'Percent',
                'Percent' => 0.8,
                'MaxAmount' => 200,
                'ForItems' => true
            ]
        );
        $discount->write();

        $calculator = Calculator::create($this->megacart);
        $this->assertSame(200, (int) $calculator->calculate());
        //clean up
        $discount->Active = false;
        $discount->write();

        //amount item discounts
        $discount = OrderDiscount::create(
            [
                'Title' => '$20 max Discount (using amount)',
                'Type' => 'Amount',
                'Amount' => 10,
                'MaxAmount' => 20,
                'ForItems' => true
            ]
        );
        $discount->write();

        $calculator = Calculator::create($this->megacart);
        $this->assertSame(20, (int) $calculator->calculate());
        //clean up
        $discount->Active = false;
        $discount->write();

        //percent cart discounts
        OrderDiscount::create(
            [
                'Title' => '40 max Discount (using amount)',
                'Type' => 'Percent',
                'Percent' => 0.8,
                'MaxAmount' => 40,
                'ForItems' => false,
                'ForCart' => true
            ]
        )->write();
        $calculator = Calculator::create($this->megacart);
        $this->assertSame(40, (int) $calculator->calculate());
    }

    public function testSavingsTotal(): void
    {
        $discount = $this->objFromFixture(OrderDiscount::class, 'limited');
        $this->assertSame(44, (int) $discount->getSavingsTotal());
        $discount = $this->objFromFixture(OrderCoupon::class, 'limited');
        $this->assertSame(22, (int) $discount->getSavingsTotal());
    }

    public function testOrderSavingsTotal(): void
    {
        $discount = $this->objFromFixture(OrderDiscount::class, 'limited');
        $order = $this->objFromFixture(Order::class, 'limitedcoupon');
        $this->assertSame(44, (int) $discount->getSavingsforOrder($order));

        $discount = $this->objFromFixture(OrderCoupon::class, 'limited');
        $order = $this->objFromFixture(Order::class, 'limitedcoupon');
        $this->assertSame(22, (int) $discount->getSavingsforOrder($order));
    }

    public function testProcessDiscountedOrder(): void
    {
        OrderDiscount::create(
            [
                'Title' => '$25 off cart total',
                'Type' => 'Amount',
                'Amount' => 25,
                'ForItems' => false,
                'ForCart' => true
            ]
        )->write();
        $order = $this->objFromFixture(Order::class, 'payablecart');
        $this->assertSame(16, (int) $order->calculate());
        $orderProcessor = OrderProcessor::create($order);
        $orderProcessor->placeOrder();
        $this->assertSame(16, (int) Order::get()->byID($order->ID)->GrandTotal());
    }
}
