<x-mail::message>
Hello,
<br>
On behalf of <b>{{$vendor->business_name}}</b> we are requesting new certificates of insurance for the following policies that have expired. Please contact the insured directly if needed.
<h3>Expired Policies:</h3>
<x-mail::panel>
@foreach($agent_expired_docs as $agent_expired_doc)
<b>{{$agent_expired_doc->type}}</b> | {{$agent_expired_doc->expiration_date->format('m/d/Y')}}<br>
@endforeach
</x-mail::panel>
<h3>Certificate Holder:</h3>
<x-mail::panel>
<b>{{$requesting_vendor->business_name}}</b>
<br>
{{$requesting_vendor->address}}
@if(!is_null($requesting_vendor->address_2))
<br>
{{$requesting_vendor->address_2}}
@endif
<br>
{{$requesting_vendor->city}}, {{$requesting_vendor->state}} {{$requesting_vendor->zip_code}}
</x-mail::panel>
@include('emails.top_footer', ["sending_vendor" => $requesting_vendor->name])
</x-mail::message>
