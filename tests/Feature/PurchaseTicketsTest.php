<?php

namespace Tests\Feature;

use App\User;
use App\Concert;
use Tests\TestCase;
use App\Facades\TicketCode;
use App\Billing\PaymentGateway;
use App\Billing\FakePaymentGateway;
use App\Mail\OrderConfirmationEmail;
use Illuminate\Support\Facades\Mail;
use App\Facades\OrderConfirmationNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentGateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->paymentGateway);
        Mail::fake();
    }

    private function orderTickets($concert, $params)
    {
        $savedRequest = $this->app['request'];
        $this->response = $this->json('POST', "/concerts/{$concert->id}/orders", $params);
        $this->app['request'] = $savedRequest;
    }

    private function assertResponseStatus($status)
    {
        $this->response->assertStatus($status);
    }

    private function seeJsonSubset($data)
    {
        $this->response->assertJson($data);
    }

    private function decodeResponseJson()
    {
        return $this->response->decodeResponseJson();
    }

    private function assertValidationError($field)
    {
        $this->response->assertStatus(422);
        $this->response->assertSee($field, $this->decodeResponseJson());
    }

    /** @test */
    function customer_can_purchase_tickets_to_a_published_concert()
    {
        OrderConfirmationNumber::shouldReceive('generate')->andReturn('ORDERCONFIRMATION123456');
        TicketCode::shouldReceive('generateFor')->andReturn('TICKETCODE1', 'TICKETCODE2', 'TICKETCODE3');

        $user = factory(User::class)->create(['stripe_account_id' => 'test_acct_1234']);
        $concert = \ConcertFactory::createPublished([
            'ticket_price' => 3250,
            'ticket_quantity' => 3,
            'user_id' => $user,
        ]);

        $this->orderTickets($concert, [
            'email' => 'john@example.com',
            'ticket_quantity' => 3,
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->assertResponseStatus(201);

        $this->seeJsonSubset([
            'confirmation_number' => 'ORDERCONFIRMATION123456',
            'email' => 'john@example.com',
            'amount' => 9750,
            'tickets' => [
                ['code' => 'TICKETCODE1'],
                ['code' => 'TICKETCODE2'],
                ['code' => 'TICKETCODE3'],
            ],
        ]);

        $this->assertEquals(9750, $this->paymentGateway->totalChargesFor('test_acct_1234'));
        $this->assertTrue($concert->hasOrderFor('john@example.com'));

        $order = $concert->ordersFor('john@example.com')->first();
        $this->assertEquals(3, $order->ticketQuantity());

        Mail::assertSent(OrderConfirmationEmail::class, function ($mail) use ($order) {
            return $mail->hasTo('john@example.com') && $mail->order->id === $order->id;
        });
    }

    /** @test */
    function cannot_purchase_tickets_to_an_unpublished_concert()
    {
        $concert = factory(Concert::class)->states('unpublished')->create()->addTickets(3);

        $this->orderTickets($concert, [
            'email' => 'john@example.com',
            'ticket_quantity' => 3,
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->response->assertStatus(404);
        $this->assertFalse($concert->hasOrderFor('john@example.com'));
        $this->assertEquals(0, $this->paymentGateway->totalCharges());
    }

    /** @test */
    function an_order_is_not_created_if_payment_fails()
    {
        $concert = factory(Concert::class)->states('published')->create(['ticket_price' => 3250])->addTickets(3);

        $this->orderTickets($concert, [
            'email' => 'arya@example.com',
            'ticket_quantity' => 3,
            'payment_token' => 'invalid-payment-token',
        ]);

        $this->response->assertStatus(422);
        $this->assertFalse($concert->hasOrderFor('arya@example.com'));
        $this->assertEquals(3, $concert->ticketsRemaining());
    }

    /** @test */
    function cannot_purchase_more_tickets_than_remain()
    {
        $concert = factory(Concert::class)->states('published')->create()->addTickets(50);

        $this->orderTickets($concert, [
            'email' => 'not-an-email-address',
            'ticket_quantity' => 51,
            'payment_token' => 'invalid-payment-token',
        ]);

        $this->response->assertStatus(422);
        $this->assertFalse($concert->hasOrderFor('john@example.com'));
        $this->assertEquals(0, $this->paymentGateway->totalCharges());
        $this->assertEquals(50, $concert->ticketsRemaining());
    }

    /** @test */
    function cannot_purchase_tickets_another_customer_is_already_trying_to_purchase()
    {
        $concert = factory(Concert::class)->states('published')->create(['ticket_price' => 1200])->addTickets(3);

        $this->paymentGateway->beforeFirstCharge(function ($paymentGateway) use ($concert) {
            $this->orderTickets($concert, [
                'email' => 'personB@gmail.com',
                'ticket_quantity' => 1,
                'payment_token' => $this->paymentGateway->getValidTestToken(),
            ]);

            $this->response->assertStatus(422);
            $this->assertFalse($concert->hasOrderFor('personB@gmail.com'));
            $this->assertEquals(0, $this->paymentGateway->totalCharges());
        });

        $this->orderTickets($concert, [
            'email' => 'personA@gmail.com',
            'ticket_quantity' => 3,
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->assertEquals(3600, $this->paymentGateway->totalCharges());
        $this->assertTrue($concert->hasOrderFor('personA@gmail.com'));
        $this->assertEquals(3, $concert->ordersFor('personA@gmail.com')->first()->ticketQuantity());
    }

    /** @test */
    function email_is_required_to_purchase_tickets()
    {
        $concert = factory(Concert::class)->states('published')->create();

        $this->orderTickets($concert, [
            'ticket_quantity' => 3,
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->assertValidationError('email');
    }

    /** @test */
    function email_must_be_valid_to_purchase_tickets()
    {
        $concert = factory(Concert::class)->states('published')->create();

        $this->orderTickets($concert, [
            'email' => 'not-an-email-address',
            'ticket_quantity' => 3,
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->assertValidationError('email');
    }

    /** @test */
    function ticket_quantity_is_required_to_purchase_tickets()
    {
        $concert = factory(Concert::class)->states('published')->create();

        $this->orderTickets($concert, [
            'email' => 'john@example.com',
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->assertValidationError('ticket_quantity');
    }

    /** @test */
    function ticket_quantity_must_be_at_least_1_to_purchase_tickets()
    {
        $concert = factory(Concert::class)->states('published')->create();

        $this->orderTickets($concert, [
            'email' => 'john@example.com',
            'ticket_quantity' => 0,
            'payment_token' => $this->paymentGateway->getValidTestToken(),
        ]);

        $this->assertValidationError('ticket_quantity');
    }

    /** @test */
    function payment_token_is_required()
    {
        $concert = factory(Concert::class)->states('published')->create();

        $this->orderTickets($concert, [
            'email' => 'john@example.com',
            'ticket_quantity' => 3,
        ]);

        $this->assertValidationError('payment_token');
    }
}
