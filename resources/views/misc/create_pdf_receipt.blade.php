@if($message_type == 'html')
  {!! $receipt_html_main !!}
@elseif($message_type == 'text')
  <!DOCTYPE html>
  <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>PDF Receipt From HTML</title>
        <link rel="stylesheet" href="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
        <style type="text/css" media="print">
            div.page
            {
                page-break-after: always;
                page-break-inside: avoid;
            }
        </style>
    </head>
    <body>
      <pre style="background-color:transparent" class="p-0 m-0">
        {!! $receipt_html_main !!}
      </pre>
    </body>
    <style type="text/css">
        .page {
            overflow: hidden;
            page-break-after: always;
        }
    </style>
  </html>
@endif
