<x-mail::message>
Hi {{$vendor->name}},
<br>
Payment details from <b>{{$paying_vendor->name}}</b>:
<x-mail::panel>
Check <a href="https://dashboard.hive.contractors/checks/{{$check->id}}"><b>{{$check_number}}</b></a>
<br>
Check Date <b>{{$check->date->format('m/d/Y')}}</b><br>
Check Total <b>{{money($check->amount)}}</b><br>
</x-mail::panel>
<h3>Project Payments:</h3>
<x-mail::panel>
@foreach($check->expenses as $expense)
<b>{{money($expense->amount)}}</b> | <a href="https://dashboard.hive.contractors/projects/{{$expense->project->id}}">{{$expense->project->name}}</a>
<br>
@endforeach
</x-mail::panel>
@include('emails.top_footer', ["sending_vendor" => $paying_vendor->name])
</x-mail::message>
