<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 导入批次功能测试。
 *
 * 注意：RefreshDatabase 会执行 migrate:fresh，请务必使用独立的测试数据库
 * （phpunit.xml 已配置 DB_DATABASE=movies_test），切勿指向开发库。
 */
class ImportBatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeCsv(array $rows, string $filename = 'movies.csv'): UploadedFile
    {
        $lines = ['title,year,director,genre,rating'];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent($filename, implode("\n", $lines) . "\n");
    }

    public function test_upload_creates_batch_record_with_counts(): void
    {
        // 预先存在的影片：导入时应计为"跳过"，且撤销时不得被删除
        Movie::create(['title' => 'Existing Movie', 'year' => 2000, 'rating' => 8]);

        $response = $this->postJson('/api/upload', [
            'file' => $this->makeCsv([
                ['New Movie A', '2020', 'Dir A', 'Drama', '7.5'],
                ['New Movie B', '2021', 'Dir B', 'Comedy', '6.5'],
                ['Existing Movie', '2000', 'Dir C', 'Drama', '9.0'],
                ['Broken Row', '', '', '', ''], // 缺少年份 → 错误行
            ]),
            'importer' => '张三',
        ]);

        $response->assertOk()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('failed', 1);

        $batchId = $response->json('batch_id');
        $this->assertNotNull($batchId);

        $batch = ImportBatch::findOrFail($batchId);
        $this->assertSame('movies.csv', $batch->filename);
        $this->assertSame('张三', $batch->importer_name);
        $this->assertSame(2, $batch->success_count);
        $this->assertSame(1, $batch->skipped_count);
        $this->assertSame(1, $batch->error_count);
        $this->assertNotEmpty($batch->errors);
        $this->assertSame(ImportBatch::STATUS_COMPLETED, $batch->status);

        // 批次明细：2 条新增 + 1 条已存在
        $this->assertSame(3, $batch->items()->count());
        $this->assertSame(2, $batch->items()->where('action', ImportBatchItem::ACTION_CREATED)->count());
        $this->assertSame(1, $batch->items()->where('action', ImportBatchItem::ACTION_UPDATED)->count());
    }

    public function test_batch_index_and_detail_list_imported_movies(): void
    {
        $response = $this->postJson('/api/upload', [
            'file' => $this->makeCsv([
                ['Movie One', '2019', 'Dir', 'Drama', '7'],
                ['Movie Two', '2020', 'Dir', 'Drama', '7'],
            ]),
            'importer' => '李四',
        ]);
        $batchId = $response->json('batch_id');

        $index = $this->getJson('/api/import-batches');
        $index->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('李四', $index->json('data.0.importer_name'));
        $this->assertSame(2, $index->json('data.0.success_count'));

        $detail = $this->getJson("/api/import-batches/{$batchId}");
        $detail->assertOk()
            ->assertJsonPath('batch.id', $batchId)
            ->assertJsonPath('batch.importer_name', '李四')
            ->assertJsonCount(2, 'items.data');

        $titles = collect($detail->json('items.data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Movie One', 'Movie Two'], $titles);

        // 每部影片都应标记为新增且仍在库中
        foreach ($detail->json('items.data') as $item) {
            $this->assertSame('created', $item['action']);
            $this->assertTrue($item['movie_exists']);
        }
    }

    public function test_revert_deletes_only_newly_created_movies(): void
    {
        Movie::create(['title' => 'Old Movie', 'year' => 1999, 'rating' => 8]);

        $response = $this->postJson('/api/upload', [
            'file' => $this->makeCsv([
                ['Old Movie', '1999', 'New Dir', 'Drama', '9'], // 已存在 → updated
                ['Fresh Movie', '2022', 'Dir', 'Drama', '7'],   // 新增 → created
            ]),
        ]);
        $batchId = $response->json('batch_id');
        $this->assertSame(2, Movie::count());

        $revert = $this->postJson("/api/import-batches/{$batchId}/revert");
        $revert->assertOk()
            ->assertJsonPath('status', 'reverted')
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('kept', 0);

        // 新增的被删除；导入前就存在的影片必须保留
        $this->assertDatabaseMissing('movies', ['title' => 'Fresh Movie', 'year' => 2022]);
        $this->assertDatabaseHas('movies', ['title' => 'Old Movie', 'year' => 1999]);

        $batch = ImportBatch::findOrFail($batchId);
        $this->assertTrue($batch->isReverted());
        $this->assertNotNull($batch->reverted_at);

        // 批次明细保留用于审计，被删影片的 movie_id 置空、标题快照仍在
        $this->assertSame(2, $batch->items()->count());
        $deletedItem = $batch->items()->where('action', ImportBatchItem::ACTION_CREATED)->first();
        $this->assertNull($deletedItem->movie_id);
        $this->assertSame('Fresh Movie', $deletedItem->title);
    }

    public function test_revert_is_not_allowed_twice(): void
    {
        $batchId = $this->postJson('/api/upload', [
            'file' => $this->makeCsv([['Solo Movie', '2023', 'Dir', 'Drama', '7']]),
        ])->json('batch_id');

        $this->postJson("/api/import-batches/{$batchId}/revert")->assertOk();
        $this->postJson("/api/import-batches/{$batchId}/revert")->assertStatus(422);
    }

    public function test_revert_keeps_movies_referenced_by_later_batches(): void
    {
        // 批次 1：新增影片
        $batch1 = $this->postJson('/api/upload', [
            'file' => $this->makeCsv([['Shared Movie', '2021', 'Dir', 'Drama', '7']]),
        ])->json('batch_id');

        // 批次 2：同一部影片（已存在 → updated）
        $batch2 = $this->postJson('/api/upload', [
            'file' => $this->makeCsv([['Shared Movie', '2021', 'Dir2', 'Drama', '8']]),
        ])->json('batch_id');

        // 撤销批次 1：影片仍被未撤销的批次 2 引用，应保留
        $this->postJson("/api/import-batches/{$batch1}/revert")
            ->assertOk()
            ->assertJsonPath('deleted', 0)
            ->assertJsonPath('kept', 1);
        $this->assertDatabaseHas('movies', ['title' => 'Shared Movie', 'year' => 2021]);

        // 撤销批次 2：只有 updated 记录，不删除任何影片
        $this->postJson("/api/import-batches/{$batch2}/revert")
            ->assertOk()
            ->assertJsonPath('deleted', 0);
        $this->assertDatabaseHas('movies', ['title' => 'Shared Movie', 'year' => 2021]);
    }
}
