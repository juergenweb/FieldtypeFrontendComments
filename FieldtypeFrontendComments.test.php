<?php

declare(strict_types=1);

namespace ProcessWire;

use FrontendComments\FrontendComment;

/**
 * WireTest for FieldtypeFrontendComments - runs inside a REAL, bootstrapped ProcessWire
 * installation with a real database, real hooks and real templates (requires the core
 * "WireTests" module, PW >= 3.0.267). Run it from your ProcessWire site root with:
 *
 *   php index.php test FieldtypeFrontendComments
 *
 * These tests deliberately complement, not replace, the stub-based PHPUnit suite in tests/
 * (which runs anywhere, without ProcessWire, and covers the module's pure logic in detail).
 * This file only covers things the stub suite structurally cannot: whether install()/upgrade()
 * actually produce valid SQL against a real database engine, whether the real hooks
 * (Pages::save, FieldtypeMulti::savePageField) actually fire in the right order with the right
 * data, and whether the module still works against whatever FrontendForms version is really
 * installed - not the hand-written stub in tests/Support/FrontendFormsStubs.php.
 *
 * IMPORTANT - please read before running this on a real site:
 * - Run this only on a disposable/dev installation, never on production. Even though every
 *   test here tries to use its own dedicated field/page and clean up after itself in finish(),
 *   a WireTest runs with full API access and a bug in this file (or an interrupted run) can
 *   leave test data behind or, in the worst case, touch real content.
 * - TEST_FIELD_NAME below must be a real Field of type FieldtypeFrontendComments that only
 *   exists for this test (not one already used by real content) - the test creates and later
 *   removes a page using it. Create it once by hand (Setup > Fields), or extend init() to
 *   create it programmatically if you prefer a fully self-contained run.
 * - I could not execute this file myself - there's no real ProcessWire+MySQL instance in the
 *   environment I wrote it in, only the stub-based PHPUnit setup. The check()/li()/ok()/fail()
 *   calls below have since been confirmed against the real WireTest base class
 *   (wire/modules/System/WireTests/WireTest.php):
 *     check($testName, $expectValue, $actualValue, $operator = '===') - throws WireTestException
 *       on failure. Supported operators: ===, !==, ==, !=, <, <=, >, >= and the string operators
 *       *= (contains), ^= (starts with), $= (ends with).
 *     li($line) - informational line. ok($line) - explicit pass line. fail($note) - throws
 *       WireTestException directly.
 */
class WireTest_FieldtypeFrontendComments extends WireTest
{
    /**
     * Name of a Field (type FieldtypeFrontendComments) reserved for this test only.
     * Create it once on your dev site before running this test.
     */
    protected const TEST_FIELD_NAME = 'test_frontendcomments_wiretest';

    /**
     * @var FieldtypeFrontendComments
     */
    protected $fieldtype;

    /**
     * @var Page|null Temporary page created in testRealCommentSaveAndQueueHook(), removed in finish()
     */
    protected $tempPage = null;

    public function allow()
    {
        return $this->wire()->modules->isInstalled('FieldtypeFrontendComments');
    }

    public function init()
    {
        $this->fieldtype = $this->wire()->modules->get('FieldtypeFrontendComments');
    }

    public function execute()
    {
        $this->testQueueTableSchema();
        $this->testCommentFieldTableSchema();
        $this->testUpgradeIsIdempotentAndSafeToRerun();
        $this->testRealCommentSaveAndQueueHook();
        $this->testFrontendFormsIntegration();
    }

    public function finish()
    {
        // clean up the temporary page/comment created in testRealCommentSaveAndQueueHook(),
        // even if an earlier assertion in this run failed
        if ($this->tempPage && $this->tempPage->id) {
            $this->tempPage->delete(true);
        }
    }

    /**
     * install() creates fc_comments_queues with a specific column set (see
     * FieldtypeFrontendComments::___install()). The stub-based Database class in the PHPUnit
     * suite only records which prepared statements get sent, it never actually parses/executes
     * SQL - so a genuine SQL syntax error or a MySQL-version incompatibility in that CREATE
     * TABLE statement would never surface there. This checks the table exists with a real
     * connection and has the columns the rest of the module relies on.
     */
    protected function testQueueTableSchema()
    {
        $database = $this->wire()->database;
        $table = FieldtypeFrontendComments::queueTable;

        $this->check("queue table '$table' exists after install()", true, (bool)$database->tableExists($table));

        $expectedColumns = ['id', 'parent_id', 'comment_id', 'email', 'page_id', 'field_id', 'claimed'];
        $result = $database->query("SHOW COLUMNS FROM $table");
        $actualColumns = $result->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($expectedColumns as $column) {
            $this->check("queue table has column '$column'", true, in_array($column, $actualColumns, true));
        }
    }

    /**
     * getDatabaseSchema() is only ever exercised by ProcessWire's real Fieldtype/Field save
     * machinery, never by the stub suite. This creates a throwaway Field of this Fieldtype (if
     * it doesn't already exist as TEST_FIELD_NAME) and checks that its real, saved database
     * table actually has the columns getDatabaseSchema() declares - most importantly the two
     * columns added by past ___upgrade() migrations (notification_confirmed, reminder_sent),
     * since those are exactly the ones a real "Unknown column" production bug was reported for.
     */
    protected function testCommentFieldTableSchema()
    {
        $fields = $this->wire()->fields;
        $field = $fields->get(self::TEST_FIELD_NAME);

        if (!$field) {
            $this->li('Skipped testCommentFieldTableSchema(): field "' . self::TEST_FIELD_NAME . '" does not exist on this installation - create it once (Setup > Fields > Add, type FieldtypeFrontendComments) to enable this check.');
            return;
        }

        $this->check('test field is of type FieldtypeFrontendComments', true, ($field->type instanceof FieldtypeFrontendComments));

        $table = $field->table;
        $database = $this->wire()->database;

        $this->check("field table '$table' exists", true, (bool)$database->tableExists($table));

        $expectedColumns = [
            'status', 'author', 'email', 'website', 'data', 'created', 'sort', 'user_id',
            'ip', 'user_agent', 'parent_id', 'code', 'remote_flag', 'notification',
            'notification_confirmed', 'reminder_sent', 'stars', 'upvotes', 'downvotes'
        ];
        $result = $database->query("SHOW COLUMNS FROM " . $database->escapeTable($table));
        $actualColumns = $result->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($expectedColumns as $column) {
            $this->check("comment field table has column '$column'", true, in_array($column, $actualColumns, true));
        }
    }

    /**
     * ___upgrade() is written to be idempotent (it checks a column's existence before altering),
     * so calling it again here on an already-current installation should be a safe no-op - this
     * both documents that expectation and catches a regression if a future edit to ___upgrade()
     * breaks that idempotency (e.g. an ALTER TABLE that isn't guarded by the column-exists check
     * and would throw "Duplicate column name" on a second run).
     */
    protected function testUpgradeIsIdempotentAndSafeToRerun()
    {
        $currentVersion = $this->wire()->modules->getModuleInfo('FieldtypeFrontendComments')['version'];

        try {
            $this->fieldtype->upgrade($currentVersion, $currentVersion);
            $this->ok('___upgrade() can be called again on an already-current install without throwing');
        } catch (\Throwable $e) {
            $this->fail('___upgrade() threw on a no-op re-run: ' . $e->getMessage());
        }
    }

    /**
     * The stub suite tests updateTables() by calling it directly with a hand-built HookEvent -
     * it never goes through ProcessWire's real hook system, so it can't catch a case where the
     * hook simply isn't firing (wrong hook name, wrong priority, removed by accident) or where
     * the event object ProcessWire actually passes doesn't have the shape the handler expects.
     * This saves a real comment through the real field API and checks, via direct SQL, that
     * FrontendComment::addCommentToQueueTable() (triggered through the real
     * FieldtypeMulti::savePageField hook) actually inserted a matching row.
     *
     * Requires TEST_FIELD_NAME to be attached to a template usable for a throwaway page under
     * the admin trash-safe branch - adjust $parentSelector to a real, disposable parent on your
     * installation before running.
     */
    protected function testRealCommentSaveAndQueueHook()
    {
        $fields = $this->wire()->fields;
        $field = $fields->get(self::TEST_FIELD_NAME);

        if (!$field) {
            $this->li('Skipped testRealCommentSaveAndQueueHook(): field "' . self::TEST_FIELD_NAME . '" does not exist - see testCommentFieldTableSchema().');
            return;
        }

        $parentSelector = 'template=admin, name=trash'; // TODO: point this at a real, disposable parent page on your dev site
        $parent = $this->wire()->pages->get($parentSelector);

        if (!$parent || !$parent->id) {
            $this->li('Skipped testRealCommentSaveAndQueueHook(): no usable parent page found via "' . $parentSelector . '" - adjust $parentSelector.');
            return;
        }

        $template = $this->findTemplateWithField($field->name);
        if (!$template) {
            $this->li('Skipped testRealCommentSaveAndQueueHook(): no template on this installation uses field "' . $field->name . '".');
            return;
        }

        $page = new Page();
        $page->template = $template;
        $page->parent = $parent;
        $page->title = 'WireTest FieldtypeFrontendComments ' . time();
        $page->save();
        $this->tempPage = $page;

        $fieldName = $field->name;
        $comments = $page->$fieldName;
        $frontendFormsConfig = FieldtypeFrontendComments::getFrontendFormsConfigValues();
        $random = new WireRandom();

        // addCommentToQueueTable() only ever queues OTHER people's email addresses - anyone who
        // opted in to be notified about new comments on this page (notification=flagNotifyAll,
        // and notification_confirmed=1 - the double opt-in flag) - and explicitly excludes the
        // new commenter's own address (see FrontendComment::addCommentToQueueTable(), step 2:
        // array_diff(..., [$this->get('email')])). A single, standalone comment with nobody else
        // subscribed therefore never produces a queue row - that's correct behaviour, not a bug
        // (an earlier version of this test got this wrong on both counts: no subscriber existed,
        // and it checked for the new commenter's own - deliberately excluded - address). So this
        // test first saves a "subscriber" comment with a confirmed notify-all opt-in, then saves
        // a second, different-author comment and checks that the SUBSCRIBER's address (not the
        // second commenter's own) ends up queued.

        $subscriberEmail = 'wiretest-subscriber@example.com';
        $subscriber = new FrontendComment($comments, [
            'author' => 'WireTest Subscriber',
            'email' => $subscriberEmail,
            'data' => 'Subscriber comment created by WireTest_FieldtypeFrontendComments.',
        ], $frontendFormsConfig);
        $subscriber->page = $page;
        $subscriber->field = $field;
        $subscriber->pages_id = $page->id;
        $subscriber->user_id = $this->wire()->user->id;
        $subscriber->ip = $this->wire()->session->getIP();
        $subscriber->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // real FrontendCommentForm.php always sets this from the form's hidden "parent-id" field
        // (0 for a top-level comment) - addCommentToQueueTable() binds it as PARAM_INT into the
        // queue table's NOT NULL parent_id column, so leaving it unset (null) makes that INSERT
        // throw (see the "Column 'parent_id' cannot be null" entry this used to leave in
        // site/assets/logs/frontend-comment.txt).
        $subscriber->parent_id = 0;
        $subscriber->sort = count($comments) + 1;
        $subscriber->created = time();
        $subscriber->status = FieldtypeFrontendComments::approved;
        $subscriber->notification = FrontendComment::flagNotifyAll;
        $subscriber->notification_confirmed = 1;
        $subscriber->code = $random->alphanumeric(120);
        $comments->saveComment($subscriber);

        $comment = new FrontendComment($comments, [
            'author' => 'WireTest Author',
            'email' => 'wiretest@example.com',
            'data' => 'Comment created by WireTest_FieldtypeFrontendComments.',
        ], $frontendFormsConfig);
        $comment->page = $page;
        $comment->field = $field;
        $comment->pages_id = $page->id;
        $comment->user_id = $this->wire()->user->id;
        $comment->ip = $this->wire()->session->getIP();
        // real FrontendCommentForm.php reads $_SERVER['HTTP_USER_AGENT'] the same unguarded way -
        // harmless here under `??` since this test runs from the CLI with no such header at all
        $comment->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $comment->parent_id = 0; // top-level comment - see the note on $subscriber->parent_id above
        $comment->sort = count($comments) + 1;
        $comment->created = time();
        $comment->status = FieldtypeFrontendComments::approved;
        $comment->code = $random->alphanumeric(120);

        // the real, public save path (used by FrontendCommentForm.php) - adds the comment if it
        // has no id yet, then calls FieldtypeMulti::savePageField() directly, which is exactly
        // what fires the correctStatusValues()/updateTables() hooks under test here
        $comments->saveComment($comment);

        $database = $this->wire()->database;
        $queueTable = FieldtypeFrontendComments::queueTable;

        $result = $database->prepare("SELECT COUNT(*) FROM $queueTable WHERE page_id = :page_id AND email = :email");
        $result->execute(['page_id' => $page->id, 'email' => $subscriberEmail]);

        $this->check(
            'saving a second, approved comment queues a notification for the earlier subscriber\'s address via the real Pages::save/FieldtypeMulti::savePageField hooks',
            true,
            ((int)$result->fetchColumn() > 0)
        );
    }

    /**
     * The module's actual anti-spam/rate-limiting and form rendering behaviour comes entirely
     * from the third-party FrontendForms module, which the PHPUnit suite replaces wholesale
     * with tests/Support/FrontendFormsStubs.php. That means an API change in a real FrontendForms
     * release (a renamed method, a changed constructor signature) would never be caught before a
     * user reports it. This is a minimal smoke test: build a real FrontendCommentForm against
     * whatever FrontendForms version is actually installed and check it renders without a fatal
     * error, rather than re-testing its full behaviour (that stays the PHPUnit suite's job).
     */
    protected function testFrontendFormsIntegration()
    {
        if (!$this->wire()->modules->isInstalled('FrontendForms')) {
            $this->li('Skipped testFrontendFormsIntegration(): FrontendForms is not installed on this test site.');
            return;
        }

        $fields = $this->wire()->fields;
        $field = $fields->get(self::TEST_FIELD_NAME);
        if (!$field) {
            $this->li('Skipped testFrontendFormsIntegration(): field "' . self::TEST_FIELD_NAME . '" does not exist - see testCommentFieldTableSchema().');
            return;
        }

        $template = $this->findTemplateWithField($field->name);
        $page = $template ? $this->wire()->pages->get("template={$template->name}, limit=1") : null;

        if (!$page || !$page->id) {
            $this->li('Skipped testFrontendFormsIntegration(): no existing page with field "' . $field->name . '" found to build a form against.');
            return;
        }

        try {
            $fieldName = $field->name;
            $comments = $page->$fieldName;
            $form = $comments->getForm();
            $markup = (string)$form->render();
            $this->check(
                'FrontendCommentForm renders non-empty markup against the real, installed FrontendForms version',
                true,
                (strlen($markup) > 0)
            );
        } catch (\Throwable $e) {
            $this->fail('building/rendering the real comment form threw: ' . $e->getMessage());
        }
    }

    /**
     * Small helper: find the first template that has the given field attached, so the test
     * doesn't need a hardcoded template name on every installation.
     */
    protected function findTemplateWithField(string $fieldName): ?Template
    {
        foreach ($this->wire()->templates as $template) {
            if ($template->hasField($fieldName)) {
                return $template;
            }
        }
        return null;
    }
}
