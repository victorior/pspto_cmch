<?php

declare(strict_types=1);

/** Estados de purchase_orders (única definición para Presupuesto y OC). */
final class PurchaseOrderStatus
{
    public const ABIERTA = 0;
    public const CERRADA = 1;
    public const ANULADA = 2;
}
