<?php

use Phinx\Migration\AbstractMigration;

class CreateOffersTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('offers')
            ->addColumn('slug', 'string', ['limit' => 120])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('advertiser', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('domain', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('source_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('niche', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('language', 'string', ['limit' => 10, 'default' => 'pt'])
            ->addColumn('structure', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('traffic_sources', 'json', ['null' => true])
            ->addColumn('platform', 'string', ['limit' => 20, 'default' => 'meta'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('score', 'integer', ['default' => 0])
            ->addColumn('ai_summary', 'text', ['null' => true])
            ->addColumn('ai_data', 'json', ['null' => true])
            ->addColumn('thumbnail_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('ads_count', 'integer', ['default' => 0])
            ->addColumn('ads_count_prev', 'integer', ['default' => 0])
            ->addColumn('scale_pct', 'integer', ['default' => 0])
            ->addColumn('first_seen_at', 'timestamp', ['null' => true])
            ->addColumn('last_seen_at', 'timestamp', ['null' => true])
            ->addColumn('approved_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['status', 'score'])
            ->addIndex(['niche'])
            ->create();

        $this->table('offer_metrics')
            ->addColumn('offer_id', 'biginteger')
            ->addColumn('ads_count', 'integer', ['default' => 0])
            ->addColumn('captured_at', 'timestamp', ['null' => true])
            ->addIndex(['offer_id', 'captured_at'])
            ->create();

        $this->table('offer_creatives')
            ->addColumn('offer_id', 'biginteger')
            ->addColumn('platform', 'string', ['limit' => 20, 'default' => 'meta'])
            ->addColumn('ad_id', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('advertiser', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('title', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('cta', 'string', ['limit' => 80, 'default' => ''])
            ->addColumn('media_type', 'string', ['limit' => 20, 'default' => 'image'])
            ->addColumn('thumbnail_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('media_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('landing_page', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('ad_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
            ->addColumn('started_at', 'date', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['offer_id'])
            ->addIndex(['platform'])
            ->create();

        $this->table('offer_pages')
            ->addColumn('offer_id', 'biginteger')
            ->addColumn('url', 'string', ['limit' => 500])
            ->addColumn('type', 'string', ['limit' => 30, 'default' => 'main'])
            ->addColumn('title', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('thumbnail_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['offer_id'])
            ->addIndex(['type'])
            ->create();

        $this->table('offer_suggestions')
            ->addColumn('offer_id', 'biginteger', ['null' => true])
            ->addColumn('type', 'string', ['limit' => 40])
            ->addColumn('payload', 'json', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['status'])
            ->create();

        $this->table('offer_views')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('offer_id', 'biginteger')
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'created_at'])
            ->create();
    }
}
