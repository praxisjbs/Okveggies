/**
 * kitchen-runs.js PR2 Fix 28
 * 3-tap native flow: mode cards -> items sheet -> delivery sheet
 * 390px 1 viewport/step, backdrop blur, 44px, help ? sheets
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-kr-form]');
  if (!form) return;

  var steps = [1,2,3].map(function(n){ return form.querySelector('[data-kr-step="'+n+'"]'); });
  var dots = [1,2,3].map(function(n){ return document.querySelector('[data-kr-step-dot="'+n+'"]'); });
  var modeInput = form.querySelector('[data-kr-input-mode]');
  var pricingInput = form.querySelector('[data-kr-pricing-mode]');
  var dateInput = form.querySelector('[data-kr-date]');
  var zoneInput = form.querySelector('[data-kr-zone]');
  var rowsHost = form.querySelector('[data-kr-rows]');
  var addBtn = form.querySelector('[data-kr-add]');
  var openBudget = form.querySelector('[data-kr-open]');
  var capField = form.querySelector('[data-kr-cap]');
  var uploadWrap = document.getElementById('kr-upload');
  var errorNote = form.querySelector('[data-kr-error]');
  var backdrop = document.getElementById('kr-backdrop');
  var helpSheet = document.getElementById('kr-help-sheet');
  var helpText = helpSheet ? helpSheet.querySelector('[data-kr-help-text]') : null;

  var currentStep = 1;

  function showStep(n){
    currentStep = n;
    steps.forEach(function(el,i){
      if(!el) return;
      el.hidden = (i+1)!==n;
    });
    dots.forEach(function(dot,i){
      if(!dot) return;
      dot.classList.remove('active','done');
      if((i+1)===n) dot.classList.add('active');
      if((i+1)<n) dot.classList.add('done');
      dot.setAttribute('aria-current', (i+1)===n ? 'step' : 'false');
    });
    window.scrollTo({top:0, behavior:'smooth'});
  }

  function showBackdrop(show){
    if(!backdrop) return;
    backdrop.hidden = !show;
    backdrop.classList.toggle('hidden', !show);
    document.body.style.overflow = show ? 'hidden' : '';
  }

  function showHelp(key){
    if(!helpSheet || !helpText) return;
    var map = {
      mode: 'Pick how your list comes. Next step is items.',
      items: 'Tap Add item. Set qty, unit, price if you have it.',
      delivery: 'Choose day and area. Address in disclosure.'
    };
    helpText.textContent = map[key] || 'We source market. You approve price.';
    helpSheet.classList.remove('hidden');
    helpSheet.hidden = false;
    showBackdrop(true);
  }

  function hideSheets(){
    [helpSheet].forEach(function(s){
      if(s){ s.classList.add('hidden'); s.hidden = true; }
    });
    showBackdrop(false);
  }

  // Mode cards
  form.querySelectorAll('[data-kr-mode]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var mode = btn.getAttribute('data-kr-mode');
      if(modeInput) modeInput.value = mode;
      var pricing = (mode==='priced') ? 'already_priced' : 'by_us';
      if(pricingInput) pricingInput.value = pricing;
      form.setAttribute('data-start', mode);
      form.querySelectorAll('[data-kr-mode]').forEach(function(b){
        var active = b.getAttribute('data-kr-mode')===mode;
        b.setAttribute('aria-pressed', active ? 'true' : 'false');
        b.classList.toggle('border-forest', active);
        b.classList.toggle('bg-forest/5', active);
        b.classList.toggle('ring-1', active);
        b.classList.toggle('ring-forest', active);
      });
      if(uploadWrap){
        var showUpload = mode==='upload';
        uploadWrap.hidden = !showUpload;
        uploadWrap.classList.toggle('hidden', !showUpload);
      }
      form.querySelectorAll('[data-kr-price-field]').forEach(function(f){
        var byUs = mode!=='priced' && mode!=='custom' ? true : (pricing==='by_us');
        // custom mode has choice, but default by_us hides price
        if(mode==='custom'){
          var byUsRadio = form.querySelector('[data-pricing][value="by_us"]');
          byUs = byUsRadio ? byUsRadio.checked : true;
        }
        f.hidden = byUs && mode!=='priced';
      });
    });
  });

  // Next/back
  form.querySelectorAll('[data-kr-next]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var next = parseInt(btn.getAttribute('data-kr-next'),10)+1;
      if(next===2){
        // validate mode chosen
        if(!modeInput || !modeInput.value){
          if(errorNote){ errorNote.textContent='Pick a mode first.'; errorNote.hidden=false; }
          return;
        }
      }
      if(next===3){
        // need at least one item or upload
        var hasItem = false;
        if(rowsHost){
          rowsHost.querySelectorAll('[data-kr-row]').forEach(function(r){
            var name = r.querySelector('[data-kr-name]');
            if(name && String(name.value).trim()!=='') hasItem=true;
          });
        }
        var mode = modeInput ? modeInput.value : '';
        var file = document.getElementById('kr-attachment');
        var hasFile = file && file.files && file.files.length>0;
        if(mode!=='upload' && !hasItem){
          if(errorNote){ errorNote.textContent='Add at least one item.'; errorNote.hidden=false; }
          showStep(2);
          return;
        }
        if(mode==='upload' && !hasFile && !hasItem){
          if(errorNote){ errorNote.textContent='Upload your list or add items.'; errorNote.hidden=false; }
          showStep(2);
          return;
        }
      }
      showStep(next);
    });
  });
  form.querySelectorAll('[data-kr-back]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var back = parseInt(btn.getAttribute('data-kr-back'),10);
      showStep(back);
    });
  });
  dots.forEach(function(dot,i){
    if(!dot) return;
    dot.addEventListener('click', function(){
      var target = i+1;
      if(target<=currentStep) showStep(target);
    });
  });

  // Rows
  function renumber(){
    if(!rowsHost) return;
    var rows = rowsHost.querySelectorAll('[data-kr-row]');
    rows.forEach(function(row, idx){
      row.querySelectorAll('[name]').forEach(function(f){
        f.name = f.name.replace(/items\[\d+\]/, 'items['+idx+']');
      });
      row.querySelectorAll('[id]').forEach(function(f){
        var fresh = f.id.replace(/-\d+$/, '-'+idx);
        var label = row.querySelector('label[for="'+f.id+'"]');
        if(label) label.setAttribute('for', fresh);
        f.id = fresh;
      });
      var label = row.querySelector('label');
      if(label) label.textContent = 'Item '+(idx+1);
    });
  }
  function addRow(){
    if(!rowsHost) return;
    var rows = rowsHost.querySelectorAll('[data-kr-row]');
    if(rows.length>=100) return;
    var clone = rows[rows.length-1].cloneNode(true);
    clone.querySelectorAll('input').forEach(function(f){ if(f.type!=='hidden' && !f.name.includes('unit_label')) f.value=''; });
    rowsHost.appendChild(clone);
    renumber();
    var name = clone.querySelector('[data-kr-name]');
    if(name) name.focus();
  }
  if(addBtn) addBtn.addEventListener('click', addRow);
  if(rowsHost){
    rowsHost.addEventListener('click', function(e){
      var rem = e.target.closest('[data-kr-remove]');
      if(!rem) return;
      var rows = rowsHost.querySelectorAll('[data-kr-row]');
      if(rows.length<=1){
        var r = rem.closest('[data-kr-row]');
        if(r){ r.querySelectorAll('input').forEach(function(f){ if(f.type!=='hidden') f.value=''; }); }
        return;
      }
      rem.closest('[data-kr-row]').remove();
      renumber();
    });
  }

  // Open budget
  if(openBudget && capField){
    var syncCap = function(){ capField.hidden = !openBudget.checked; capField.classList.toggle('hidden', !openBudget.checked); };
    openBudget.addEventListener('change', syncCap);
    syncCap();
  }

  // Day chips
  form.querySelectorAll('[data-kr-day]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var d = btn.getAttribute('data-kr-day');
      if(dateInput) dateInput.value = d;
      form.querySelectorAll('[data-kr-day]').forEach(function(b){
        b.classList.remove('border-forest','bg-forest','text-white');
        b.classList.add('border-ink-10','bg-white');
      });
      btn.classList.remove('border-ink-10','bg-white');
      btn.classList.add('border-forest','bg-forest','text-white');
    });
  });
  // Zone chips
  form.querySelectorAll('[data-kr-zone-btn]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var z = btn.getAttribute('data-kr-zone-btn');
      if(zoneInput) zoneInput.value = z;
      form.querySelectorAll('[data-kr-zone-btn]').forEach(function(b){
        b.classList.remove('border-forest','bg-forest','text-white');
        b.classList.add('border-ink-10','bg-white');
      });
      btn.classList.remove('border-ink-10','bg-white');
      btn.classList.add('border-forest','bg-forest','text-white');
    });
  });

  // Help
  form.querySelectorAll('[data-kr-help]').forEach(function(btn){
    btn.addEventListener('click', function(){ showHelp(btn.getAttribute('data-kr-help')); });
  });
  if(backdrop){
    backdrop.addEventListener('click', hideSheets);
  }
  document.querySelectorAll('[data-kr-close]').forEach(function(btn){
    btn.addEventListener('click', hideSheets);
  });

  // Submit via fetch
  function showError(msg){
    if(!errorNote) return;
    errorNote.textContent = msg;
    errorNote.hidden = !msg;
    if(msg) errorNote.scrollIntoView({block:'nearest'});
  }
  function endpoint(){ return form.getAttribute('action') || '/api/v1/kitchen_runs.php'; }
  form.addEventListener('submit', function(e){
    e.preventDefault();
    showError('');
    var btn = form.querySelector('button[type="submit"]');
    if(btn) btn.disabled = true;
    fetch(endpoint(), {
      method:'POST',
      credentials:'same-origin',
      headers:{'X-Requested-With':'fetch','Accept':'application/json'},
      body:new FormData(form)
    }).then(function(res){
      return res.json().then(function(body){ return {ok:res.ok, body:body}; });
    }).then(function(result){
      if(result.ok && result.body && result.body.id){
        window.location.href = '/kitchen-runs.php?request='+encodeURIComponent(result.body.id)+'&submitted=1';
        return;
      }
      if(btn) btn.disabled = false;
      showError((result.body && result.body.message) || 'Could not send list. Try again.');
    }).catch(function(){
      if(btn) btn.disabled = false;
      showError('No connection. Check and try again.');
    });
  });

  showStep(1);
})();
