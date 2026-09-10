<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SafePcre.php';

class PageManager
{
    private string $pagesFile;

    public function __construct()
    {
        $this->pagesFile = Config::getDataDir() . '/pages.json';
        if (!file_exists($this->pagesFile)) {
            file_put_contents($this->pagesFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function list(): array
    {
        if (Database::available()) {
            return \AfiliaFacil\Models\Page::orderBy('created_at', 'asc')->get()
                ->map(fn($p) => $this->toArray($p, false))
                ->all();
        }
        return $this->read();
    }

    public function get(int $id): ?array
    {
        if (Database::available()) {
            $page = \AfiliaFacil\Models\Page::find($id);
            if (!$page) return null;
            return $this->toArray($page, true);
        }

        foreach ($this->read() as $page) {
            if ($page['id'] === $id) return $page;
        }
        return null;
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? (time() + random_int(1, 9999));
        $name = $data['name'] ?? 'Sem nome';
        $html = $data['html'] ?? '';

        $page = [
            'id' => $id,
            'user_id' => $data['user_id'] ?? (int)($_SESSION['user_id'] ?? 1),
            'name' => $name,
            'slug' => $data['slug'] ?? self::slugify($name),
            'type' => $data['type'] ?? 'landing',
            'status' => $data['status'] ?? 'active',
            'domain' => $data['domain'] ?? '',
            'affiliate_link' => $data['affiliate_link'] ?? '',
            'source_domain' => $data['source_domain'] ?? '',
            'failed_assets' => $data['failed_assets'] ?? [],
            'cloner_version' => $data['cloner_version'] ?? '',
            'views' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (Database::available()) {
            \AfiliaFacil\Models\Page::create([
                'id' => $page['id'],
                'user_id' => $page['user_id'],
                'name' => $page['name'],
                'slug' => $page['slug'],
                'type' => $page['type'],
                'status' => $page['status'],
                'domain' => $page['domain'],
                'affiliate_link' => $page['affiliate_link'],
                'source_domain' => $page['source_domain'],
                'failed_assets' => $page['failed_assets'],
                'cloner_version' => $page['cloner_version'],
                'views' => 0,
                'created_at' => $page['created_at'],
                'updated_at' => $page['updated_at'],
            ]);
        } else {
            $pages = $this->read();
            $stored = $page;
            $stored['html'] = $html;
            $pages[] = $stored;
            $this->write($pages);
        }

        if (!empty($html)) {
            $this->writeHtmlFile($page['id'], $html);
        }

        $page['html'] = $html;
        return $page;
    }

    public function update(int $id, array $data): ?array
    {
        $page = $this->get($id);
        if (!$page) return null;

        $fields = [];
        foreach (['name', 'status', 'domain', 'affiliate_link', 'source_domain', 'slug', 'type', 'user_id', 'failed_assets', 'cloner_version'] as $field) {
            if (array_key_exists($field, $data)) $fields[$field] = $data[$field];
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');

        if (Database::available()) {
            $model = \AfiliaFacil\Models\Page::find($id);
            if (!$model) return null;
            foreach ($fields as $k => $v) $model->$k = $v;
            $model->save();
        } else {
            $pages = $this->read();
            foreach ($pages as &$p) {
                if ($p['id'] === $id) {
                    foreach ($fields as $k => $v) $p[$k] = $v;
                    break;
                }
            }
            unset($p);
            if (isset($data['html'])) {
                foreach ($pages as &$p) {
                    if ($p['id'] === $id) { $p['html'] = $data['html']; break; }
                }
                unset($p);
            }
            $this->write($pages);
        }

        if (isset($data['html'])) {
            $this->writeHtmlFile($id, $data['html']);
        }

        return $this->get($id);
    }

    public function delete(int $id): bool
    {
        if (Database::available()) {
            $model = \AfiliaFacil\Models\Page::find($id);
            if ($model) $model->delete();
        } else {
            $pages = $this->read();
            $pages = array_filter($pages, fn($p) => $p['id'] !== $id);
            $this->write(array_values($pages));
        }

        $dir = Config::getPagesDir() . '/' . $id;
        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }

        return true;
    }

    private function removeDirectory(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }

    public function countByUser(int $userId): int
    {
        if (Database::available()) {
            return \AfiliaFacil\Models\Page::where('user_id', $userId)->count();
        }
        return count(array_filter($this->read(), fn($p) => ($p['user_id'] ?? 1) === $userId));
    }

    public function incrementViews(int $id): void
    {
        if (Database::available()) {
            \AfiliaFacil\Models\Page::where('id', $id)->increment('views');
            return;
        }
        $pages = $this->read();
        foreach ($pages as &$page) {
            if ($page['id'] === $id) {
                $page['views'] = ($page['views'] ?? 0) + 1;
                break;
            }
        }
        unset($page);
        $this->write($pages);
    }

    public function getStats(): array
    {
        $pages = $this->list();
        return [
            'total' => count($pages),
            'active' => count(array_filter($pages, fn($p) => $p['status'] === 'active')),
            'draft' => count(array_filter($pages, fn($p) => $p['status'] === 'draft')),
            'total_views' => array_sum(array_column($pages, 'views')),
        ];
    }

    private function toArray($model, bool $withHtml): array
    {
        $data = [
            'id' => (int)$model->id,
            'user_id' => (int)$model->user_id,
            'name' => $model->name,
            'slug' => $model->slug,
            'type' => $model->type,
            'status' => $model->status,
            'domain' => $model->domain,
            'affiliate_link' => $model->affiliate_link,
            'source_domain' => $model->source_domain,
            'failed_assets' => $model->failed_assets ?? [],
            'cloner_version' => $model->cloner_version ?? '',
            'views' => (int)$model->views,
            'created_at' => (string)$model->created_at,
            'updated_at' => (string)$model->updated_at,
        ];

        if ($withHtml) {
            $data['html'] = $this->readHtmlFile((int)$model->id);
        }

        return $data;
    }

    private function htmlFile(int $id): string
    {
        return Config::getPagesDir() . '/' . $id . '/index.html';
    }

    private function writeHtmlFile(int $id, string $html): void
    {
        $dir = Config::getPagesDir() . '/' . $id;
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($this->htmlFile($id), $html);
    }

    private function readHtmlFile(int $id): string
    {
        $file = $this->htmlFile($id);
        return file_exists($file) ? (string)file_get_contents($file) : '';
    }

    private function read(): array
    {
        if (!file_exists($this->pagesFile)) return [];
        return json_decode(file_get_contents($this->pagesFile), true) ?? [];
    }

    private function write(array $pages): void
    {
        file_put_contents($this->pagesFile, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = SafePcre::replace('/[^a-z0-9-]/', '-', $text);
        $text = SafePcre::replace('/-+/', '-', $text);
        return trim($text, '-');
    }
}
