A lightweight PHP wrapper for Stripe payments, designed for projects that prefer explicit control over payment flow without tightly coupling business logic to controllers.

This library provides a unified interface for:

Creating Stripe Checkout Sessions (Web)

Creating PaymentIntents (App / Mobile)

Verifying payment status via Webhook and server-side polling

The implementation intentionally keeps Stripe logic isolated in a single gateway class, making it easy to:

Replace Stripe with another payment provider

Refactor to pure HTTP requests if needed

Maintain clear separation between payment infrastructure and application logic

No framework assumptions are enforced.
Composer is optional.
Suitable for legacy PHP and Laravel-based projects.
