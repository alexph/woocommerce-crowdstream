<script>
crowdstream.events.identify("<?php echo $this->identify_data['id']; ?>", <?php echo json_encode($this->identify_data['params']); ?>);
</script>
