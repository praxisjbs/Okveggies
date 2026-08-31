<?php
/**
 * includes/classes/BasketError.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One refusal from the basket, carrying two things: a short reason
 * the controller can switch on, and the sentence the customer reads. The
 * message is always written here, never built from an exception the database
 * threw, so nothing internal can reach the browser.
 *
 * It extends DomainException so any older catch block still holds.
 * -----------------------------------------------------------------------------
 */

final class BasketError extends DomainException
{
    private string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /** A short machine reason: not_found, unavailable, below_minimum, over_ceiling, invalid_quantity. */
    public function reason(): string
    {
        return $this->reason;
    }
}
