$(document).ready(function () {
    $("#players [data-player-name]").hover(
        function () {
            $(this).addClass('highlight');
            $("#matches [data-player-name='"+$(this).data('player-name')+"']").addClass('highlight');
        },
        function () {
            $(this).removeClass('highlight');
            $("#matches [data-player-name='"+$(this).data('player-name')+"']").removeClass('highlight');
        }
    );
});
