(function ()
{
 function Refresh()
 {
  $('#label-jobs-error').text('');
  Victual.Api.Get('labels/jobs', function (jobs)
  {
   var rows = $('#label-jobs-rows').empty();
   var states = { awaiting_artifact: __t('Waiting for label rendering'), awaiting_authorization: __t('Awaiting authorization'), claimed: __t('Claimed'), sent: __t('Sent'), reported: __t('Printed'), failed: __t('Failed'), blocked: __t('Blocked'), uncertain: __t('Delivery uncertain'), uncertain_but_reported: __t('Delivery uncertain; a late report arrived'), dead_lettered: __t('Cannot deliver') };
   jobs.forEach(function (job)
   {
    var row = $('<tr>');
    [job.id, job.printer_name || job.printer_id, states[job.state] || job.state, job.error_text || '', job.printer_status_age === null ? __t('Never reported') : Math.floor(Number(job.printer_status_age))].forEach(function (value)
    { row.append($('<td>').text(value)); });
    var action = $('<td>');
    if (['failed', 'blocked', 'uncertain', 'uncertain_but_reported'].includes(job.state) && job.attempts_made === job.attempts_authorized)
    {
     action.append($('<button>').addClass('btn btn-warning').text(__t('Authorize another attempt')).on('click', function ()
     {
      if (!window.confirm(__t('The previous attempt may have printed. Authorize another attempt after reviewing its outcome?'))) return;
      Victual.Api.Post('labels/jobs/' + job.id + '/authorize-attempt', { attempt_id: job.current_attempt_id }, Refresh, Failed);
     }));
    }
    row.append(action); rows.append(row);
   });
  }, Failed);
 }
 function Failed() { $('#label-jobs-error').text(__t('Could not load or update label jobs.')); }
 $('#label-jobs-refresh').on('click', Refresh);
 Refresh();
})();
