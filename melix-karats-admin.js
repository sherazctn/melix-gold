(function($){
    $(document).ready(function(){

        // Show/hide API settings depending on Use API checkbox
        function toggleApiSettings() {
            var checked = $('#melix_use_api').is(':checked');
            if (checked) $('#melix-api-settings').show();
            else $('#melix-api-settings').hide();
        }
        $(document).on('change', '#melix_use_api', toggleApiSettings);
        toggleApiSettings(); // init

        // helper: disable/enable price fields
        function disablePriceFields(){
            var sel = 'input[name="regular_price"], input[name="_regular_price"], #_regular_price, input[name="sale_price"], input[name="_sale_price"], #_sale_price';
            $(sel).prop('disabled', true).attr('readonly', true).css({'background':'#eee'});
            $('input[id^="regular_price"]').prop('disabled', true).attr('readonly', true).css({'background':'#eee'});
        }
        function enablePriceFields(){
            var sel = 'input[name="regular_price"], input[name="_regular_price"], #_regular_price, input[name="sale_price"], input[name="_sale_price"], #_sale_price';
            $(sel).prop('disabled', false).removeAttr('readonly').css({'background':''});
            $('input[id^="regular_price"]').prop('disabled', false).removeAttr('readonly').css({'background':''});
        }

        // compute simple product price and disable fields if karat selected
        function computeSimple(){
            var $karat = $('select[name="melix_karat"]');
            var $weight = $('#melix_weight');
            var $markup = $('#melix_markup');
            if (!$karat.length) return;
        
            var karat = $karat.val();
            var weight = parseFloat($weight.val()) || 0;
            var markup = parseFloat($markup.val()) || 0;
        
            if (!karat || weight <= 0) {
                enablePriceFields();
                return;
            }
        
            var rate = MelixKaratsData && MelixKaratsData.rates
                ? parseFloat(MelixKaratsData.rates[karat] || 0)
                : 0;
        
            if (!rate) {
                enablePriceFields();
                return;
            }
        
            var gap = parseFloat(MelixKaratsData.market_gap || 0);
            var adjustedRate = rate + gap;
        
            var base = adjustedRate * weight;
            var extra = base * (markup / 100);
            var price = Math.round((base + extra) * 100) / 100;
        
            var $reg = $('input[name="regular_price"], input[name="_regular_price"], #_regular_price');
            if ($reg.length) {
                $reg.val(price).trigger('change');
            }
        
            disablePriceFields();
        }

        $(document).on('input change', '#melix_karat, #melix_weight, #melix_markup', computeSimple);

        // variation compute: per variation id
        function computeVariationForId(id){
            var selK = 'select[name="melix_karat['+id+']"]';
            var selW = 'input[name="melix_weight['+id+']"]';
            var selM = 'input[name="melix_markup['+id+']"]';
            var metal = $(selK).val();
            var weight = parseFloat($(selW).val()) || 0;
            var markup = parseFloat($(selM).val()) || 0;
            if (!metal || weight <= 0) return;
            var rate = MelixKaratsData && MelixKaratsData.rates ? parseFloat(MelixKaratsData.rates[metal]||0) : 0;
            if (!rate) return;
            var gap = parseFloat(MelixKaratsData.market_gap || 0);
            var base = (rate + gap) * weight;
            var extra = base * (markup/100);
            var price = Math.round((base + extra) * 100) / 100;

            var $reg = $('input[name="variable_regular_price['+id+']"]');
            if (!$reg.length) $reg = $('input[name="_regular_price"], #_regular_price');
            if ($reg.length){ $reg.val(price).trigger('change').prop('disabled', true).attr('readonly', true).css({'background':'#eee'}); }
            $('input[name="variable_sale_price['+id+']"]').prop('disabled', true).attr('readonly', true).css({'background':'#eee'});
        }

        $(document).on('input change', 'select[name^="melix_karat"], input[name^="melix_weight"], input[name^="melix_markup"]', function(){
            var name = $(this).attr('name'); if (!name) return;
            var idMatch = name.match(/\d+/);
            if (!idMatch) return;
            var id = idMatch[0];
            computeVariationForId(id);
        });

        // extra: ensure fields disabled when karat selected even after focus/typing
        $(document).on('focus', 'input[name="regular_price"], input[name="_regular_price"], input[name="sale_price"], input[name="_sale_price"]', function(){
            if ($('#melix_karat').length && $('#melix_karat').val()){
                disablePriceFields();
            }
        });

        // MutationObserver to handle dynamic UI and new product screens (Avada)
        var observer = new MutationObserver(function(muts){
            muts.forEach(function(m){
                if (m.addedNodes && m.addedNodes.length){
                    computeSimple();
                    $('select[name^="melix_karat"]').each(function(){
                        var name = $(this).attr('name'); var idMatch = name ? name.match(/\d+/) : null;
                        if (idMatch) computeVariationForId(idMatch[0]);
                    });
                }
            });
        });
        observer.observe(document.body, { childList:true, subtree:true });

        // load more logs
        var offset = 20; var limit = 20; var maxShow = 100;
        $('#melix-load-more').on('click', function(){
            $.post(MelixKaratsData.ajax_url, { action: 'melix_load_logs', offset: offset, limit: limit }, function(resp){
                if (resp.success){
                    var rows = resp.data.rows;
                    if (rows.length){
                        $('#melix-logs-list').append(rows.join(''));
                        offset += rows.length;
                        if (offset >= resp.data.count || offset >= maxShow) $('#melix-load-more').hide();
                        $('#melix-logs-counter').text('Showing '+Math.min(offset,maxShow)+' of '+resp.data.count);
                    } else { $('#melix-load-more').hide(); }
                } else { $('#melix-load-more').hide(); }
            });
        });

        // initial state
        setTimeout(function(){ computeSimple(); }, 600);
        $.post(MelixKaratsData.ajax_url, { action:'melix_load_logs', offset:0, limit:1 }, function(resp){ if (resp.success){ $('#melix-logs-counter').text('Total logs: '+resp.data.count); if (resp.data.count<=20) $('#melix-load-more').hide(); } });

    });
})(jQuery);



jQuery(function($){

    function updateMarketGapPreview(){
        var gap = parseFloat(MelixKaratsData.market_gap || 0);

        $('.melix-gap-preview').each(function(){
            var karat = $(this).data('karat');
            var rateInput = $('#melix_rate_' + karat);

            if (!rateInput.length) {
                $(this).html('');
                return;
            }
            
            var rate = parseFloat(rateInput.val());
            if (isNaN(rate)) {
                $(this).html('');
                return;
            }

            var finalRate = rate + gap;

            $(this).html(
                '<small style="color:#666;">After Market Gap: <strong>' +
                finalRate.toFixed(2) +
                ' AED / gram</strong></small>'
            );
        });
    }

    // Initial load
    updateMarketGapPreview();

    // On rate change
    $('input[id^="melix_rate_"]').on('input', updateMarketGapPreview);

    // On market gap change
    $('input[name="market_gap"]').on('input', function(){
        MelixKaratsData.market_gap = parseFloat($(this).val() || 0);
        updateMarketGapPreview();
    });

});