$(".js-media-item").hide();
$(".js-media-item").slice(0, 12).show();

$( document ).ready(function() {
    if($(".js-media-item:visible").length === $(".js-media-item").length) {
        $(".js-load-more-container").hide();
        $(".js-load-more-without-pagination").hide();
    }
});

$(".js-load-more-without-pagination").click(function() {
    const showing = $(".js-media-item:visible").length;
    $(".js-media-item").slice(showing - 1, showing + 8).show();

    if($(".js-media-item:visible").length === $(".js-media-item").length) {
        $(".js-load-more-container").hide();
        $(".js-load-more-without-pagination").hide();
    }
});
