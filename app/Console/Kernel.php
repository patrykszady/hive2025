<?php
protected function schedule(Schedule $schedule)
{
    // Add this line alongside your other scheduled commands
    $schedule->command('tasks:send-morning-updates')->dailyAt('07:00');
    
    // ...existing scheduled commands...
}