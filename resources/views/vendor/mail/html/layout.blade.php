<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
:root {
color-scheme: light dark;
}

@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}

@media (prefers-color-scheme: dark) {
/* Project cards */
.project-card {
border-color: #52525b !important;
}
.project-title,
.project-title a {
color: #e2e8f0 !important;
}
.project-subtitle {
color: #94a3b8 !important;
}

/* Task cards */
.task-card {
border-color: #52525b !important;
}
.task-time,
.task-day-counter {
color: #94a3b8 !important;
}
.vendor-name {
color: #94a3b8 !important;
}

/* Date headers */
.date-header {
color: #cbd5e1 !important;
}
.date-header-today {
color: #818cf8 !important;
}
.badge-today {
background-color: #312e81 !important;
color: #a5b4fc !important;
}
.badge-tomorrow {
background-color: #27272a !important;
color: #a1a1aa !important;
}
.badge-no-tasks {
background-color: #27272a !important;
color: #71717a !important;
}
.subtitle-text {
color: #94a3b8 !important;
}
}
</style>
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{{ $header ?? '' }}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{{ Illuminate\Mail\Markdown::parse($slot) }}

{{ $subcopy ?? '' }}
</td>
</tr>
</table>
</td>
</tr>

{{ $footer ?? '' }}
</table>
</td>
</tr>
</table>
</body>
</html>
