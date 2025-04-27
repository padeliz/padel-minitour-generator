// keep the session alive
setInterval(() => {
    location.reload(); // Refresh the page
}, 600000); // 600,000 ms = 10 minutes

$(function () {
    if ($('#timer').length) {
        $("body").simpletimer({
            day: $("#days").html(),
            dayDom: "#days",
            hour: $("#hours").html(),
            hourDom: "#hours",
            minute: $("#minutes").html(),
            minuteDom: "#minutes",
            second: $("#seconds").html(),
            secondDom: "#seconds",
            endFun: function () {
                window.location.reload();
            }
        });
    }

    if ($('#button--rejected-by-lucky-one').length || $("#button--accepted-by-lucky-one").length) {
        $.ajax({
            url: Web.url('site._ajax.lottery.announce-lucky-one'),
            type: 'POST',
            dataType: 'JSON',
            data: {
                ajax_token: Form.token('ajax'),
                form_token: Form.token('form'),
                id_lucky: $('[name="id_lucky"]').val(),
                id_edition: $('[name="id_edition"]').val(),
            },
            error: function (response) {
                if (response.status == 401 || response.status == 403) {
                    alert("Old session. Refresh page and try again.");
                }
            }
        });

        $("#button--rejected-by-lucky-one").on('click', function () {
            if (window.confirm("REJECT the prize ??")) {
                $('button').prop('disabled', true);

                $.ajax({
                    url: Web.url('site._ajax.lottery.rejected-by-lucky-one'),
                    type: 'POST',
                    dataType: 'JSON',
                    data: {
                        ajax_token: Form.token('ajax'),
                        form_token: Form.token('form'),
                        id_lucky: $('[name="id_lucky"]').val(),
                        id_edition: $('[name="id_edition"]').val(),
                    },
                    error: function (response) {
                        if (response.status == 401 || response.status == 403) {
                            alert("Old session. Refresh page and try again.");
                        }
                    },
                    success: function (response) {
                        window.location.reload();
                    }
                });
            }
        });

        $("#button--accepted-by-lucky-one").on('click', function () {
            if (window.confirm("ACCEPT the prize ??")) {
                $('button').prop('disabled', true);

                $.ajax({
                    url: Web.url('site._ajax.lottery.accepted-by-lucky-one'),
                    type: 'POST',
                    dataType: 'JSON',
                    data: {
                        ajax_token: Form.token('ajax'),
                        form_token: Form.token('form'),
                        id_lucky: $('[name="id_lucky"]').val(),
                        id_edition: $('[name="id_edition"]').val(),
                    },
                    error: function (response) {
                        if (response.status == 401 || response.status == 403) {
                            alert("Old session. Refresh page and try again.");
                        }
                    },
                    success: function (response) {
                        window.location.reload();
                    }
                });
            }
        });
    }
});
