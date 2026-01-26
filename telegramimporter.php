<?php

// All comments in this file are now in English.

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\Registry\Registry;
use Joomla\CMS\Log\Log;

class PlgSystemTelegramimporter extends CMSPlugin
{
    protected $autoloadLanguage = true;

    private function ensureUtf8mb4Connection(): void
    {
        try {
            $db = Joomla\CMS\Factory::getDbo();

            // Try to set charset directly on mysqli object
            if (method_exists($db, 'getConnection')) {
                $conn = $db->getConnection();
                if ($conn instanceof \mysqli) {
                    @$conn->set_charset('utf8mb4');
                }
            }

            // Duplicate via SET NAMES and explicit character_set_*
            $db->setQuery("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'")->execute();
            $db->setQuery("SET character_set_client = 'utf8mb4'")->execute();
            $db->setQuery("SET character_set_connection = 'utf8mb4'")->execute();
            $db->setQuery("SET character_set_results = 'utf8mb4'")->execute();
        } catch (\Throwable $e) {
            // Ignore errors
        }
    }

    public function onAfterRoute()
    {
        $app = Factory::getApplication();

        // Do not run in admin
        if ($app->isClient('administrator')) {
            return;
        }

        // Import interval (minutes)
        $interval = (int) $this->params->get('import_interval', 10);
        $lastRun  = (int) $this->params->get('last_run', 0);
        $now      = time();

        if ($interval > 0 && $lastRun && ($now - $lastRun) < ($interval * 60)) {
            return;
        }

        // Logging to logs/telegramimporter.log
        Log::addLogger(
            ['text_file' => 'telegramimporter.log', 'extension' => 'tgimport'],
            Log::ALL,
            ['tgimport']
        );

        try {
            // Start import
            $this->importPosts();

            // Update last successful run timestamp
            $this->updateParam('last_run', $now);
        } catch (\Throwable $e) {
            // Log quietly and let the site continue
            Log::add('TG importer failed: ' . $e->getMessage(), Log::ERROR, 'tgimport');
            return;
        }
    }

    public function importPosts()
    {
        $botToken    = trim((string) $this->params->get('bot_token'));
        $channelId   = (int) $this->params->get('channel_id');
        $categoryId  = (int) $this->params->get('category_id');
        $importLimit = (int) $this->params->get('import_limit', 10);
        $authorId    = (int) $this->params->get('author_id', 0) ?: (int) Factory::getUser()->id;

        if (!$botToken || !$channelId || !$categoryId) {
            return;
        }

        // Set utf8mb4 on connection BEFORE any operations
        $this->ensureUtf8mb4Connection();

        // Safety: import history table
        $this->ensureImportedTable();

        // Include helper
        $helperPath = JPATH_PLUGINS . '/system/telegramimporter/src/Helper/TelegramHelper.php';
        if (!is_file($helperPath)) {
            return;
        }
        require_once $helperPath;

        $lastUpdateId = (int) $this->params->get('last_update_id', 0);
        $helper = new \PlgSystemTelegramImporter\Helper\TelegramHelper($botToken, $channelId);

        $result          = $helper->getLatestPosts($importLimit, $channelId, $lastUpdateId);
        $posts           = $result['posts'] ?? [];
        $newLastUpdateId = (int) ($result['last_update_id'] ?? $lastUpdateId);

        foreach ($posts as $post) {
            $msgId  = (int) ($post['message_id'] ?? 0);
            $chatId = (int) ($post['channel_id'] ?? 0);

            if ($this->isPostAlreadyImported($msgId, $chatId)) {
                continue;
            }

            // Only clean "??" artifacts in text nodes (not inside tags)
            $post['intro'] = $this->stripDoubleQuestionMarks($post['intro'] ?? '');
            $post['body']  = $this->stripDoubleQuestionMarks($post['body'] ?? '');

            $articleId = $this->createArticle($post, $authorId, $categoryId);
            $this->markPostAsImported($post['id'], $msgId, $chatId, $articleId);

            if ($articleId) {
                $this->ensureWorkflowStage((int) $articleId);
            }
        }

        if ($newLastUpdateId > $lastUpdateId) {
            $this->updateParam('last_update_id', $newLastUpdateId);
        }
    }

    private function createArticle(array $post, int $authorId, int $categoryId): ?int
    {
        $db     = Factory::getDbo();
        $nowUtc = Factory::getDate()->toSql(); // Joomla stores dates in UTC

        // Build alias base with Ukrainian transliteration ("и" -> "y")
        $aliasBase = $this->prepareAliasBase($post['title'] ?? '');
        $alias     = $this->makeAliasUnique($aliasBase, $categoryId);

        $article = Table::getInstance('Content', 'JTable');

        // Basic fields
        $article->set('catid',       $categoryId);
        $article->set('title',       $post['title'] ?? '');
        $article->set('alias',       $alias);

        // HTML with cleaned "??"
        $article->set('introtext',   $post['intro'] ?? '');
        $article->set('fulltext',    $post['body'] ?? '');

        $article->set('created',     $nowUtc);
        $article->set('state',       1);
        $article->set('created_by',  $authorId);
        $article->set('access',      1);
        $article->set('hits',        0);
        $article->set('featured',    0);
        $article->set('version',     1);
        $article->set('language',    '*');

        // Correct publish dates
        $article->set('publish_up',   $nowUtc);
        $article->set('publish_down', null);

        // Service JSON
        $article->set('images',   '{}');
        $article->set('urls',     '{}');
        $article->set('attribs',  '{}');
        $article->set('metadesc', '');
        $article->set('metakey',  '');
        $article->set('metadata', '{}');
        $article->set('metarobots', '');

        // Image
        if (!empty($post['image'])) {
            $imagePath = $this->downloadImage($post['image']);
            if ($imagePath) {
                $article->set('images', json_encode(['image_intro' => $imagePath], JSON_UNESCAPED_SLASHES));
            }
        }

        if (!$article->check()) {
            return null;
        }

        if (!$article->store()) {
            return null;
        }

        // Ensure publish dates (safety)
        $this->ensurePublishDates((int) $article->id, $nowUtc);

        return (int) $article->id;
    }

    /**
     * Prepares base string for alias: "и/И" -> "y/Y", then URL-clean.
     */
    private function prepareAliasBase(string $title): string
    {
        $map = ['и' => 'y', 'И' => 'Y'];
        $prepared = strtr($title ?? '', $map);
        $base     = OutputFilter::stringURLSafe($prepared);
        return $base ?: ('post-' . uniqid());
    }

    /**
     * Ensures alias uniqueness within category.
     */
    private function makeAliasUnique(string $base, int $categoryId): string
    {
        $db = Factory::getDbo();
        $i  = 0;

        do {
            $alias = $base . ($i ? '-' . $i : '');
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('alias') . ' = ' . $db->quote($alias))
                ->where($db->quoteName('catid') . ' = ' . (int) $categoryId);
            $db->setQuery($query);
            $exists = (bool) $db->loadResult();
            $i++;
        } while ($exists);

        return $alias;
    }

    private function downloadImage(string $url): ?string
    {
        $helperPath = JPATH_PLUGINS . '/system/telegramimporter/src/Helper/TelegramHelper.php';
        if (!is_file($helperPath)) {
            return null;
        }
        require_once $helperPath;

        $helper = new \PlgSystemtelegramimporter\Helper\TelegramHelper(
            (string) $this->params->get('bot_token'),
            (int) $this->params->get('channel_id')
        );
        return $helper->downloadImage($url);
    }

    private function isPostAlreadyImported(int $messageId, int $channelId): bool
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__tg_imported_posts'))
            ->where($db->quoteName('message_id') . ' = ' . (int) $messageId)
            ->where($db->quoteName('channel_id') . ' = ' . (int) $channelId);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    private function markPostAsImported(int $postId, ?int $messageId, ?int $channelId, ?int $articleId): void
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__tg_imported_posts'))
            ->columns($db->quoteName(['post_id', 'message_id', 'channel_id', 'imported_at', 'article_id']))
            ->values(
                (int) $postId . ', ' .
                ($messageId !== null ? (int) $messageId : 'NULL') . ', ' .
                ($channelId !== null ? (int) $channelId : 'NULL') . ', ' .
                $db->quote(Factory::getDate()->toSql()) . ', ' .
                ($articleId !== null ? (int) $articleId : 'NULL')
            );
        $db->setQuery($query)->execute();
    }

    private function ensureImportedTable(): void
    {
        $db = Factory::getDbo();
        $db->setQuery(
            "CREATE TABLE IF NOT EXISTS " . $db->quoteName('#__tg_imported_posts') . " (
                id INT unsigned NOT NULL AUTO_INCREMENT,
                post_id BIGINT signed NULL,
                message_id BIGINT signed NULL,
                channel_id BIGINT signed NULL,
                imported_at DATETIME NOT NULL,
                article_id INT unsigned NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_channel_message (channel_id, message_id),
                KEY idx_post_id (post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        )->execute();
    }

    private function updateParam(string $key, $value): void
    {
        $table = Table::getInstance('extension');
        $table->load([
            'type'   => 'plugin',
            'folder' => 'system',
            'element'=> 'telegramimporter'
        ]);

        $params = new Registry($table->params);
        $params->set($key, $value);
        $table->params = $params->toString();
        $table->store();
    }

    /**
     * Hard: publish_up=now (UTC), publish_down=NULL.
     */
    private function ensurePublishDates(int $articleId, ?string $nowUtc = null): void
    {
        $db     = Factory::getDbo();
        $nowUtc = $nowUtc ?: Factory::getDate()->toSql();

        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__content'))
                ->set($db->quoteName('publish_up') . ' = ' . $db->quote($nowUtc))
                ->set($db->quoteName('publish_down') . ' = NULL')
                ->where($db->quoteName('id') . ' = ' . (int) $articleId);
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Ignore errors
        }
    }

    /**
     * Sets workflow association only for a specific article.
     */
    private function ensureWorkflowStage(int $articleId): void
    {
        $db = Factory::getDbo();
        $preferredStageId = 1;

        try {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__workflow_stages'))
                ->where($db->quoteName('id') . ' = ' . (int) $preferredStageId)
                ->where($db->quoteName('published') . ' = 1');
            $db->setQuery($query);
            $existsPreferred = (int) $db->loadResult() > 0;

            $stageId = $existsPreferred ? $preferredStageId : 0;

            if ($stageId <= 0) {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('stage_id'))
                    ->from($db->quoteName('#__workflow_associations'))
                    ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content.article'))
                    ->where($db->quoteName('stage_id') . ' IS NOT NULL')
                    ->where($db->quoteName('stage_id') . ' <> 0')
                    ->order($db->quoteName('item_id') . ' ASC')
                    ->setLimit(1);
                $db->setQuery($query);
                $stageId = (int) $db->loadResult();
            }

            if ($stageId <= 0) {
                $query = $db->getQuery(true)
                    ->select('s.' . $db->quoteName('id'))
                    ->from($db->quoteName('#__workflows', 'w'))
                    ->join('INNER', $db->quoteName('#__workflow_stages', 's') . ' ON s.' . $db->quoteName('workflow_id') . ' = w.' . $db->quoteName('id'))
                    ->where('w.' . $db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                    ->where('w.' . $db->quoteName('published') . ' = 1')
                    ->where('s.' . $db->quoteName('published') . ' = 1')
                    ->order('w.' . $db->quoteName('id') . ' ASC, s.' . $db->quoteName('ordering') . ' ASC')
                    ->setLimit(1);
                $db->setQuery($query);
                $stageId = (int) $db->loadResult();
            }

            if ($stageId <= 0) {
                return;
            }

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__workflow_associations'))
                ->where($db->quoteName('item_id') . ' = ' . (int) $articleId);
            $db->setQuery($query)->execute();

            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__workflow_associations'))
                ->columns([$db->quoteName('extension'), $db->quoteName('item_id'), $db->quoteName('stage_id')])
                ->values(
                    $db->quote('com_content.article') . ', ' .
                    (int) $articleId . ', ' .
                    (int) $stageId
                );
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Ignore errors
        }
    }

    /**
     * Cleans only text nodes from sequences of "??" (and longer "???"),
     * without changing content inside tags (<...>), to avoid breaking attributes.
     */
    private function stripDoubleQuestionMarks(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Split into tags and text; clean only text segments
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts) {
            return $html;
        }

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            // If this is a tag — leave as is
            if ($part[0] === '<') {
                continue;
            }
            // In text, remove any sequence of two or more "?"
            $parts[$i] = preg_replace('/\?{2,}/u', '', $part) ?? $part;
        }

        return implode('', $parts);
    }
}