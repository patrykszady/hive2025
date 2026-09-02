<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait Sortable
{
    public static function bootSortable()
    {
        static::addGlobalScope(function ($query) {
            return $query->orderBy('order');
        });

        static::creating(function ($model) {
            // Scope by estimate_id for EstimateSection
            $query = static::query();
            if (isset($model->estimate_id)) {
                $query->where('estimate_id', $model->estimate_id);
            }

            if ($query->count() === 0) {
                $model->order = 0;
            } else {
                $model->order = $query->max('order') + 1;
            }
        });

        static::deleting(function ($model) {
            $model->displace();
        });
    }

    public function move($position)
    {
        DB::transaction(function () use ($position) {
            // The block-shift below is only correct over a gapless 0..N-1
            // sequence — and real data drifts from that constantly: creation
            // numbers estimate line items ESTATE-wide while moves are scoped
            // per-section, cross-section moves and displaced rows leave gaps
            // and offsets. The UI sends the target INDEX in the visible list,
            // so anything but 0..N-1 lands the row in the wrong place.
            //
            // Renumber first, every time. (This used to be a 2-in-10 Lottery,
            // which both left 8 in 10 moves running over dirty data AND used
            // a stale $this->order for the shift when it did fire.)
            $this->arrange();
            $this->refresh();

            $current = $this->order;
            $after = $position;

            //If there was no position change, dont shift
            if ($current === $after) {
                return;
            }

            // move the target todo out of the position stack
            $this->update(['order' => -1]);

            //Grab the shifted block and shift it up or down
            $block = static::sortable($this)->whereBetween('order', [
                min($current, $after),
                max($current, $after),
            ]);

            $needToShiftBlockBecauseDraggingTargetDown = $current < $after;

            $needToShiftBlockBecauseDraggingTargetDown
                ? $block->decrement('order')
                : $block->increment('order');

            //place target back in position stack
            $this->update(['order' => $after]);
        });
    }

    public function arrange()
    {
        DB::transaction(function () {
            $position = 0;

            // Sort in PHP, not SQL: bootSortable's global scope appends its
            // own orderBy('order') when the query executes — i.e. AFTER any
            // orderBy chained here — so a chained ->orderBy('id') silently
            // became the PRIMARY sort and renumbered rows into creation
            // order, flinging the newest item to the end of the section.
            // Here `order` leads and `id` only breaks ties.
            $models = static::sortable($this)->get()
                ->sortBy([['order', 'asc'], ['id', 'asc']]);

            // Quiet saves: renumbering is bookkeeping — running observers and
            // activity logs across every sibling on each drag is noise.
            foreach ($models as $model) {
                if ((int) $model->order !== $position) {
                    $model->order = $position;
                    $model->saveQuietly();
                }

                $position++;
            }
        });
    }

    public function displace()
    {
        //999999 = $position. CHANGE!!! because on soft deleted models it stays in the database
        $this->move(999999);
    }
}
