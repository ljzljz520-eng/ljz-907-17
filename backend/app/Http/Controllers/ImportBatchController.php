<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportBatchController extends Controller
{
    /**
     * 导入批次列表（最新在前）
     */
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);

        $batches = ImportBatch::query()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json($batches);
    }

    /**
     * 批次详情：回看该批次导入了哪些影片
     */
    public function show(Request $request, $id)
    {
        $batch = ImportBatch::find($id);
        if (!$batch) {
            return response()->json(['error' => 'Batch not found'], 404);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $items = $batch->items()
            ->orderBy('id')
            ->paginate($perPage);

        // 标记影片当前是否仍在库中（movie_id 为 null 表示已被删除/撤销）
        // 注意不能用 "exists" 命名：Model 自带 public $exists 属性，不会序列化
        $items->getCollection()->transform(function ($item) {
            $item->movie_exists = $item->movie_id !== null;
            return $item;
        });

        return response()->json([
            'batch' => $batch,
            'items' => $items,
        ]);
    }

    /**
     * 按批次撤销导入。
     *
     * 只删除本批次"新增"（created）的影片；
     * 导入前就已存在的影片（updated）绝不删除。
     * 若某部新增影片后来被其他未撤销的批次引用（例如被后续导入更新过），
     * 为避免误删他人数据，该影片会被保留并计入 kept。
     */
    public function revert($id)
    {
        $batch = ImportBatch::find($id);
        if (!$batch) {
            return response()->json(['error' => 'Batch not found'], 404);
        }

        if ($batch->isReverted()) {
            return response()->json(['error' => '该批次已撤销，不能重复操作'], 422);
        }

        $deletedCount = 0;
        $keptCount = 0;

        DB::transaction(function () use ($batch, &$deletedCount, &$keptCount) {
            $createdMovieIds = $batch->items()
                ->where('action', ImportBatchItem::ACTION_CREATED)
                ->whereNotNull('movie_id')
                ->pluck('movie_id')
                ->unique();

            foreach ($createdMovieIds as $movieId) {
                $referencedElsewhere = ImportBatchItem::query()
                    ->where('movie_id', $movieId)
                    ->where('import_batch_id', '!=', $batch->id)
                    ->whereHas('batch', function ($q) {
                        $q->where('status', '!=', ImportBatch::STATUS_REVERTED);
                    })
                    ->exists();

                if ($referencedElsewhere) {
                    $keptCount++;
                    continue;
                }

                $deletedCount += Movie::where('id', $movieId)->delete();
            }

            $batch->status = ImportBatch::STATUS_REVERTED;
            $batch->reverted_at = now();
            $batch->save();
        });

        return response()->json([
            'status' => 'reverted',
            'batch_id' => $batch->id,
            'deleted' => $deletedCount,
            'kept' => $keptCount,
        ]);
    }
}
