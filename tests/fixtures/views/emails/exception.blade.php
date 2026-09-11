<h1>{{ $Message }}</h1>
<p>{{ $Exception }} in {{ $File }}:{{ $Line }}</p>
<p>{{ $Method }} {{ $URL }} ({{ $IP ?? 'no-ip' }})</p>
<p>{{ $Time }} ({{ $Timezone }})</p>
<pre>{{ json_encode($Input) }}</pre>
@foreach ($Trace as $entry)
<div>{{ $entry['file'] }}:{{ $entry['line'] }} {{ $entry['class'] }} {{ $entry['function'] }}</div>
@endforeach