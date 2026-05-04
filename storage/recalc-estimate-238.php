<?php

use App\Models\Bid;
use App\Models\Estimate;
use App\Models\EstimateSection;

$estimate = Estimate::withoutGlobalScopes()->findOrFail(238);
echo 'Estimate '.$estimate->id.' (project '.$estimate->project_id.')'.PHP_EOL;

$sections = $estimate->estimate_sections()->withTrashed()->with('estimate_line_items')->get();
$bidIds = [];

foreach ($sections as $section) {
    $oldTotal = (float) $section->total;
    $lineSum = 0.0;
    foreach ($section->estimate_line_items as $li) {
        $expected = round(((float) $li->cost) * ((float) $li->quantity), 2);
        if ((float) $li->total !== $expected) {
            echo '  LineItem '.$li->id.' total '.$li->total.' -> '.$expected.PHP_EOL;
            $li->total = $expected;
            $li->saveQuietly();
        }
        $lineSum += (float) $li->total;
    }
    $lineSum = round($lineSum, 2);
    if ($oldTotal !== $lineSum) {
        echo 'Section '.$section->id.' ('.$section->name.') total '.$oldTotal.' -> '.$lineSum.PHP_EOL;
        $section->total = $lineSum;
        $section->saveQuietly();
    } else {
        echo 'Section '.$section->id.' ('.$section->name.') total OK ('.$lineSum.')'.PHP_EOL;
    }
    if ($section->bid_id) {
        $bidIds[] = $section->bid_id;
    }
}

foreach (array_unique($bidIds) as $bidId) {
    $bid = Bid::find($bidId);
    if (! $bid) {
        continue;
    }
    $old = (float) $bid->amount;
    $new = round((float) EstimateSection::where('bid_id', $bid->id)->sum('total'), 2);
    if ($old !== $new) {
        echo 'Bid '.$bid->id.' amount '.$old.' -> '.$new.PHP_EOL;
        $bid->amount = $new;
        $bid->save();
    } else {
        echo 'Bid '.$bid->id.' amount OK ('.$new.')'.PHP_EOL;
    }
}

$grand = round((float) $estimate->estimate_sections()->sum('total'), 2);
echo 'Estimate grand total: '.$grand.PHP_EOL;
