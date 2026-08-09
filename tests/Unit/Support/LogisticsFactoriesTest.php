<?php

namespace Tests\Unit\Support;

use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use App\Models\ResourceItem;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * Harness de la 0056: las factories de logística tienen que producir datos que
 * el resto de las pruebas de caracterización pueda dar por buenos.
 */
class LogisticsFactoriesTest extends TestCase
{
    public function test_el_recurso_de_inventario_nace_con_stock_y_rastreo(): void
    {
        $recurso = ResourceItem::factory()->create();

        $this->assertTrue($recurso->is_inventory_tracked);
        $this->assertSame(100, $recurso->stock_quantity);
        $this->assertSame(0, $recurso->reserved_quantity);
        $this->assertSame(100, $recurso->available_quantity);
    }

    public function test_el_recurso_de_efectivo_no_lleva_inventario(): void
    {
        $recurso = ResourceItem::factory()->cash()->create();

        $this->assertSame('cash', $recurso->category);
        $this->assertFalse($recurso->is_inventory_tracked);
        $this->assertNull($recurso->stock_quantity);
    }

    public function test_la_asignacion_y_su_item_quedan_enlazados_con_el_subtotal_calculado(): void
    {
        $tenant = Tenant::factory()->create();
        $recurso = ResourceItem::factory()->create(['tenant_id' => $tenant->id, 'unit_cost' => 15000]);
        $asignacion = ResourceAllocation::factory()->paraTenant($tenant)->create();

        $item = ResourceAllocationItem::factory()->de($asignacion, $recurso, 4)->create();

        $this->assertTrue($asignacion->items()->exists());
        $this->assertEquals(60000, $item->subtotal);
        $this->assertSame($recurso->id, $item->resourceItem->id);
    }
}
