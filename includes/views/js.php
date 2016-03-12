<?php if(count($this->event_queue) > 0): ?>
<script>
<?php

foreach($this->event_queue as $event) {
    echo 'crowdstream.events.' . $event['method'] . '(';
    if($event['event']) {
        echo '"' . $event['event'] .'"';
        if($event['params']) {
            echo ', ';
        }
    }
    if($event['params']) {
        $line = array();
        foreach($event['params'] as $param) {
            if(is_array($param)) {
                $line[] = json_encode($param);
            } else {
                $line [] = $param;
            }
        }
        if($line) {
            echo implode(', ', $line);
        }
    }
    echo ');' . "\n";
}

?>
</script>
<?php endif; ?>
<?php if ($this->has_events_in_cookie): ?>
<script>
	jQuery(document).ready(function($) {
		$.post("<?php echo admin_url('admin-ajax.php'); ?>", {'action': 'crowdstream_clear'}, function(response) {});
	});
</script>
<?php endif; ?>
