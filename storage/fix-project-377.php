$p = \App\Models\Project::withoutGlobalScopes()->find(377);
echo 'Project 377 client_id: ' . $p->client_id . PHP_EOL;

// Fix projects table
if ($p->client_id != 301) {
    $p->client_id = 301;
    $p->saveQuietly();
    echo 'projects.client_id updated to 301.' . PHP_EOL;
} else {
    echo 'projects.client_id already 301.' . PHP_EOL;
}

// Fix project_vendor pivot table
$updated = \DB::table('project_vendor')
    ->where('project_id', 377)
    ->where('client_id', '!=', 301)
    ->update(['client_id' => 301]);
echo 'project_vendor rows updated: ' . $updated . PHP_EOL;

// Verify
$pivotClient = \DB::table('project_vendor')->where('project_id', 377)->pluck('client_id');
echo 'Verified pivot client_ids: ' . $pivotClient->implode(', ') . PHP_EOL;

// Delete client 302 if no references remain
$c302 = \App\Models\Client::withoutGlobalScopes()->find(302);
if ($c302) {
    $hasProjects = \DB::table('project_vendor')->where('client_id', 302)->exists();
    if (!$hasProjects) {
        $c302->users()->detach();
        $c302->vendors()->detach();
        $c302->unsearchable();
        $c302->delete();
        echo 'Client 302 deleted.' . PHP_EOL;
    } else {
        echo 'Client 302 still has project_vendor refs, skipping delete.' . PHP_EOL;
    }
} else {
    echo 'Client 302 already deleted.' . PHP_EOL;
}
