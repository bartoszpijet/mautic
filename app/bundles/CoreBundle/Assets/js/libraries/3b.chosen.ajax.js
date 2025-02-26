// Mautic note: Seem to be coming from bootstrap-overflow-navs.js but this library seem to be modifield for Mautic so cannot be simply replaced with the original one via npm.
// Generated by CoffeeScript 1.4.0

(function ($) {
    return $.fn.ajaxChosen = function (settings, callback, chosenOptions) {
        var chosenXhr, defaultOptions, options, select;
        if (settings == null) {
            settings = {};
        }
        if (chosenOptions == null) {
            chosenOptions = {};
        }
        defaultOptions = {
            minTermLength: 3,
            afterTypeDelay: 500,
            jsonTermKey: "search",
            keepTypingMsg: mauticLang["mautic.core.lookup.keep_typing"],
            lookingForMsg: mauticLang["mautic.core.lookup.looking_for"]
        };
        select = this;
        chosenXhr = null;
        options = $.extend({}, defaultOptions, $(select).data(), settings);
        this.chosen(chosenOptions ? chosenOptions : {});

        // Check for a "new" value
        var hasNew = false;
        if ($(select).find('option[value="new"]').length) {
            hasNew = $(select).find('option[value="new"]');
        }

        return this.each(function () {
            return $(this).next('.chosen-container').find(".search-field > input, .chosen-search > input").on('keyup', function (event) {
                if (event.which === 8 || event.which === 93 || event.which === 17 || event.which === 18) {
                    return false;
                }

                var field, msg, success, untrimmed_val, val, search_field;
                untrimmed_val = $(this).val();
                val = $.trim($(this).val());
                msg = val.length < options.minTermLength ? options.keepTypingMsg : options.lookingForMsg + (" '" + val + "'");
                select.next('.chosen-container').find('.no-results').text(msg);
                if (val === $(this).data('prevVal')) {
                    return false;
                }
                $(this).data('prevVal', val);
                if (this.timer) {
                    clearTimeout(this.timer);
                }
                if (val.length < options.minTermLength) {
                    return false;
                }
                field = $(this);
                if (options.data == null) {
                    options.data = {};
                }
                options.data['field'] = /.+\.(.*)/.exec(options.jsonTermKey)[1];
                options.data['filter'] = val;
                if (options.dataCallback != null) {
                    options.data = options.dataCallback(options.data);
                }
                success = options.success;
                options.success = function (data) {
                    var items, nbItems, selected_values;
                    if (data == null) {
                        return;
                    }
                    selected_values = [];
                    select.find('option').each(function () {
                        if (!$(this).is(":selected")) {
                            return $(this).remove();
                        } else {
                            return selected_values.push($(this).val() + "-" + $(this).text());
                        }
                    });
                    select.find('optgroup:empty').each(function () {
                        return $(this).remove();
                    });
                    items = callback != null ? callback(data, field) : data;
                    nbItems = 0;

                    $.each(items, function (i, element) {
                        var group, text, value;
                        nbItems++;
                        if (element.group) {
                            group = select.find("optgroup[label='" + element.text + "']");
                            if (!group.length) {
                                group = $("<optgroup />");
                            }
                            group.attr('label', element.text).appendTo(select);
                            return $.each(element.items, function (i, element) {
                                var text, value;
                                if (typeof element === "string") {
                                    value = i;
                                    text = element;
                                } else {
                                    value = element.value;
                                    text = element.text;
                                }
                                if ($.inArray(value + "-" + text, selected_values) === -1) {
                                    return $("<option />").attr('value', value).html(text).appendTo(group);
                                }
                            });
                        } else {
                            if (typeof element === "string") {
                                value = i;
                                text = element;
                            } else {
                                value = element.value;
                                text = element.text;
                            }
                            if ($.inArray(value + "-" + text, selected_values) === -1) {
                                return $("<option />").attr('value', value).html(text).appendTo(select);
                            }
                        }
                    });
                    if (nbItems) {
                        // Re-append new back to the top
                        if (hasNew) {
                            hasNew.prependTo(select);
                        }
                        select.trigger("chosen:updated");

                        setTimeout( function() {
                            // Hack to force chosen to hide already selected values from the list
                            var e = $.Event("keyup.chosen");
                            e.which = 93; // Windows/Command
                            field.trigger(e);
                        }, 5);
                    } else {
                        select.data().chosen.no_results_clear();
                        select.data().chosen.no_results(field.val());
                    }
                    if (settings.success != null) {
                        settings.success(data);
                    }
                    var returnVar = field.val(untrimmed_val);

                    // Force width
                    div = $('<div />');
                    div.text(untrimmed_val);
                    $('body').append(div);
                    w = div.width() + 25;
                    f_width = field.closest('.chosen-choices').outerWidth();

                    // Mautic - only apply container width if not hidden which will result in a bad size
                    if (w > f_width - 10) {
                        w = f_width;
                    }

                    div.remove();
                    field.css({
                        'width': w + 'px'
                    });

                    return returnVar;
                };
                return this.timer = setTimeout(function () {
                    if (chosenXhr) {
                        chosenXhr.abort();
                    }
                    return chosenXhr = $.ajax(options);
                }, options.afterTypeDelay);
            });
        });
    };
})(jQuery);