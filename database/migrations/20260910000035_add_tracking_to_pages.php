<?php

use Phinx\Migration\AbstractMigration;

class AddTrackingToPages extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pages')
            ->addColumn('meta_pixel_id', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('meta_capi_token', 'text', ['null' => true, 'default' => null])
            ->addColumn('google_conversion_id', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('google_conversion_label', 'string', ['limit' => 80, 'default' => ''])
            ->addColumn('tiktok_pixel_id', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('capi_test_code', 'string', ['limit' => 60, 'default' => ''])
            ->update();
    }
}
