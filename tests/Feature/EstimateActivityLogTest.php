<?php

use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use Spatie\Activitylog\LogOptions;

it('configures activity logging on EstimateLineItem', function () {
    $item = new EstimateLineItem();
    $options = $item->getActivitylogOptions();

    expect($options)->toBeInstanceOf(LogOptions::class);

    // Verify the log name via reflection since there's no public getter
    $reflection = new ReflectionProperty(LogOptions::class, 'logName');
    expect($reflection->getValue($options))->toBe('estimates');
});

it('configures activity logging on EstimateSection', function () {
    $section = new EstimateSection();
    $options = $section->getActivitylogOptions();

    expect($options)->toBeInstanceOf(LogOptions::class);

    $reflection = new ReflectionProperty(LogOptions::class, 'logName');
    expect($reflection->getValue($options))->toBe('estimates');
});

it('EstimateLineItem uses LogsActivity trait', function () {
    expect(in_array(
        \Spatie\Activitylog\Traits\LogsActivity::class,
        class_uses_recursive(EstimateLineItem::class)
    ))->toBeTrue();
});

it('EstimateSection uses LogsActivity trait', function () {
    expect(in_array(
        \Spatie\Activitylog\Traits\LogsActivity::class,
        class_uses_recursive(EstimateSection::class)
    ))->toBeTrue();
});
