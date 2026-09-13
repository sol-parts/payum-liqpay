<?php

/*
 * This file is part of SOLPARTS
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\PayumLiqPay\Request\Api;

use Payum\Core\Request\Generic;

/**
 * Рендер сторінки передачі покупця в LiqPay (redirect-форма або віджет).
 *
 * Підписану пару `data`/`signature` request несе сам: це per-render похідна від
 * details ({@see \SolParts\PayumLiqPay\Api::buildCheckoutPayload()}), у сховище
 * транзакції вона не потрапляє.
 */
class ObtainToken extends Generic
{
    public function __construct(
        mixed $model,
        private readonly string $data,
        private readonly string $signature,
    ) {
        parent::__construct($model);
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }
}
