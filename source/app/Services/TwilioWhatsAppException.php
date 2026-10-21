<?php
namespace App\Services;

/** Only numeric error metadata; never retain provider bodies or verification codes. */
class TwilioWhatsAppException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly ?int $providerCode = null)
    {
        parent::__construct($httpStatus >= 500 || $httpStatus < 400 ? 'twilio_unknown' : 'twilio_rejected_'.$httpStatus);
    }
}
