$(document).ready(function () {
    // Store original matches data once at the beginning
    const originalMatchesData = getOriginalMatchesData();
    let userHasInteracted = false;
    let allIntervals = []; // Global list to track all intervals and timeouts
    let inflightXhr = null; // Active distribution-recalc AJAX request, if any

    $("#players tr").hover(
        // mouseenter
        function (event) {
            const playerId = $(this).find("[data-player-id]").data('player-id');
            const $playerCell = $(this).find("[data-player-id]");
            const $matchCells = $("#matches [data-player-id='"+playerId+"']");

            // Only mark user interaction if it's a real user event (not programmatic)
            if (!event.isTrigger) {
                userHasInteracted = true;
            }

            // Get the player's distribution class and apply corresponding hover class
            const $distributionCell = $(".distribution-index[data-player-id='" + playerId + "']");
            let hoverClass = null;

            if ($distributionCell.hasClass('excellent')) {
                hoverClass = 'player-hover-excellent';
            } else if ($distributionCell.hasClass('good')) {
                hoverClass = 'player-hover-good';
            } else if ($distributionCell.hasClass('fair')) {
                hoverClass = 'player-hover-fair';
            } else if ($distributionCell.hasClass('poor')) {
                hoverClass = 'player-hover-poor';
            }

            // Apply the hover class to both player name and matches
            if (hoverClass) {
                $playerCell.addClass(hoverClass);
                $matchCells.addClass(hoverClass);
            }
        },
        // mouseleave
        function (event) {
            const playerId = $(this).find("[data-player-id]").data('player-id');
            const $playerCell = $(this).find("[data-player-id]");
            const $matchCells = $("#matches [data-player-id='"+playerId+"']");

            // Remove all hover classes
            $playerCell.removeClass('player-hover-excellent player-hover-good player-hover-fair player-hover-poor');
            $matchCells.removeClass('player-hover-excellent player-hover-good player-hover-fair player-hover-poor');
        }
    );

    // Calculate distribution indices on page load
    calculateDistributionIndices();

    // Make matches table sortable
    $("#matches tbody").sortable({
        // Remove handle restriction to allow dragging from anywhere
        helper: function(e, tr) {
            const $originals = tr.children();
            const $helper = tr.clone();
            $helper.children().each(function(index) {
                $(this).width($originals.eq(index).width());
            });
            return $helper;
        },
        start: function(event, ui) {
            // Clear all existing intervals and timeouts
            clearAllIntervals();

            // Store which row is being dragged on the element itself
            ui.item.data('dragged-index', ui.item.data('match-index'));

            // Add visual feedback when starting to drag
            ui.item.addClass('dragging');
            ui.placeholder.addClass('drop-placeholder');

            // Highlight the original position (rows above and below)
            const originalIndex = ui.item.data('match-index');
            const $allRows = $("#matches tbody tr");

            // Highlight row above original position
            if (originalIndex > 0) {
                $allRows.eq(originalIndex - 1).addClass('original-position-above');
            }

            // Highlight row below original position
            if (originalIndex < $allRows.length - 1) {
                $allRows.eq(originalIndex + 1).addClass('original-position-below');
            }
        },
        stop: function(event, ui) {
            // Remove visual feedback when dropping
            ui.item.removeClass('dragging');

            // Remove original position highlights
            $("#matches tbody tr").removeClass('original-position-above original-position-below');
        },
        change: function(event, ui) {
            // Add smooth animation when position changes
            ui.placeholder.addClass('drop-placeholder');
        },
        update: function(event, ui) {
            // Update row numbers with smooth animation
            $("#matches tbody tr").each(function(index) {
                $(this).find("th").fadeOut(100, function() {
                    $(this).text(index + 1).fadeIn(100);
                });
            });

            // Update hidden inputs in both forms
            updateHiddenInputs();

            // Highlight only the moved row
            highlightMovedRow();

            // Recalculate distribution indices after reordering
            calculateDistributionIndices();
        },
        // Add smooth animation duration
        duration: 300,
        // Add distance threshold to prevent accidental drags
        distance: 5
    });

    function clearAllIntervals() {
        allIntervals.forEach(interval => {
            if (interval.type === 'timeout') {
                clearTimeout(interval.id);
            } else if (interval.type === 'interval') {
                clearInterval(interval.id);

                $(`#players tbody tr:has([data-player-id])`).trigger('mouseleave');
            }
        });
        allIntervals = [];
    }

    function addInterval(id, type) {
        allIntervals.push({ id, type });
    }

    function calculateDistributionIndices() {
        // Show loading state
        $(".distribution-index").html('<span class="loading-dots"></span>');

        // Abort any in-flight recalc request (cancel-in-flight policy):
        // a fresh reorder always supersedes the previous one.
        if (inflightXhr) {
            inflightXhr.abort();
            inflightXhr = null;
        }

        // Build the request payload from the current DOM order. Hidden times in slots
        // [2] and [3] aren't needed by the scorer (the algorithm only looks at player IDs
        // in slots [0][0..1] and [1][0..1]) so we ship the lighter shape.
        const matchesPayload = [];
        $("#matches tbody tr").each(function() {
            const originalIndex = $(this).data('match-index');
            const data = originalMatchesData[originalIndex];
            if (!data) {
                return;
            }
            matchesPayload.push([
                [data[0][0], data[0][1]],
                [data[1][0], data[1][1]]
            ]);
        });

        const players = [];
        $("#players tbody tr").each(function() {
            const playerId = $(this).find("[data-player-id]").data('player-id');
            players.push(playerId);
        });

        inflightXhr = $.ajax({
            url: Web.url('site._ajax.matches.calculate-distribution'),
            type: 'POST',
            dataType: 'JSON',
            data: {
                ajax_token: Form.token('ajax'),
                form_token: Form.token('form'),
                matches: matchesPayload,
                player_ids: players
            }
        });

        inflightXhr.done(function(response) {
            inflightXhr = null;

            const perPlayer = (response && response.values && response.values.perPlayer) || {};

            // Preserve the existing UX timing: 1.5 s initial loading-dot delay, then a
            // 200 ms per-player stagger, then the worst-player hover demo after the last
            // cell renders. The math is precomputed by the server; the stagger is purely
            // cosmetic continuity with the prior client-side flow.
            const calculationTimeout = setTimeout(() => {
                players.forEach(function(playerId, index) {
                    const playerTimeout = setTimeout(() => {
                        const payload = perPlayer[playerId];
                        if (payload) {
                            updatePlayerDistributionIndex(playerId, payload.percentage, payload.cssClass);
                        }

                        // After all players are calculated, demo the hover feature on the worst player
                        if (index === players.length - 1) {
                            const demoTimeout = setTimeout(() => {
                                demoHoverFeature();
                            }, 500);
                            addInterval(demoTimeout, 'timeout');
                        }
                    }, index * 200); // 200ms delay between each player

                    addInterval(playerTimeout, 'timeout');
                });
            }, 1500); // 1.5 second delay

            addInterval(calculationTimeout, 'timeout');
        });

        inflightXhr.fail(function(xhr, status) {
            // Aborted requests are expected -- a fresh reorder superseded this one.
            if (status === 'abort') {
                return;
            }
            inflightXhr = null;

            if (xhr.status === 401 || xhr.status === 403) {
                alert("Old session. Refresh page and try again.");
            }

            // Surface a clearly-failed state on every cell so stale loading dots never linger.
            $(".distribution-index").each(function() {
                $(this).removeClass('excellent good fair poor').addClass('poor').html('-');
            });
        });
    }

    function demoHoverFeature() {
        // Don't run demo if user has already interacted
        if (userHasInteracted) {
            return;
        }

        // Find the worst-rated player
        let worstPlayer = null;
        let worstScore = 1;

        $(".distribution-index").each(function() {
            const $cell = $(this);
            const playerId = $cell.data('player-id');
            const score = parseFloat($cell.text()) / 100; // Convert percentage back to decimal

            if (score < worstScore) {
                worstScore = score;
                worstPlayer = playerId;
            }
        });

        if (worstPlayer) {
            const $worstPlayerRow = $(`#players tbody tr:has([data-player-id="${worstPlayer}"])`);

            // Demo hover effect (blink 3 times)
            let blinkCount = 0;
            const maxBlinks = 3;

            const demoInterval = setInterval(() => {
                if (blinkCount % 2 === 0) {
                    // Trigger hover in
                    $worstPlayerRow.trigger('mouseenter');
                } else {
                    // Trigger hover out
                    $worstPlayerRow.trigger('mouseleave');
                }

                blinkCount++;
                if (blinkCount >= maxBlinks * 2) {
                    clearInterval(demoInterval);
                    // Remove from global list
                    allIntervals = allIntervals.filter(interval => interval.id !== demoInterval);
                }
            }, 800); // 800ms per blink cycle

            addInterval(demoInterval, 'interval');
        }
    }

    function updatePlayerDistributionIndex(playerId, percentage, cssClass) {
        const $cell = $(".distribution-index[data-player-id='" + playerId + "']");

        $cell.removeClass('excellent good fair poor').addClass(cssClass);
        $cell.html(percentage + '%');
    }

    function updateHiddenInputs() {
        const newOrder = [];

        // Get the new order of matches
        $("#matches tbody tr").each(function() {
            newOrder.push($(this).data('match-index'));
        });

        // Update hidden inputs in both forms (Preview and PDF)
        $("form").each(function() {
            const $form = $(this);
            const $hiddenInputs = $form.find("input[name^='matches[']");

            if ($hiddenInputs.length > 0) {
                // Remove existing hidden inputs
                $hiddenInputs.remove();

                // Add new hidden inputs in the correct order
                newOrder.forEach(function(originalIndex, newPosition) {
                    const oldMatch = originalMatchesData[originalIndex];
                    const newMatch = originalMatchesData[newPosition];

                    if (oldMatch && newMatch) {
                        // Reorder the match data (players) but keep times in original positions
                        $form.append('<input type="hidden" name="matches[' + newPosition + '][0][0]" value="' + oldMatch[0][0] + '" />');
                        $form.append('<input type="hidden" name="matches[' + newPosition + '][0][1]" value="' + oldMatch[0][1] + '" />');
                        $form.append('<input type="hidden" name="matches[' + newPosition + '][1][0]" value="' + oldMatch[1][0] + '" />');
                        $form.append('<input type="hidden" name="matches[' + newPosition + '][1][1]" value="' + oldMatch[1][1] + '" />');
                        // Keep times in their original positions (not reordered)
                        $form.append('<input type="hidden" name="matches[' + newPosition + '][2]" value="' + newMatch[2] + '" />');
                        $form.append('<input type="hidden" name="matches[' + newPosition + '][3]" value="' + newMatch[3] + '" />');
                    }
                });
            }
        });
    }

    function highlightMovedRow() {
        // Find the row that was dragged and highlight it
        $("#matches tbody tr").each(function() {
            const draggedIndex = $(this).data('dragged-index');
            if (draggedIndex !== undefined) {
                $(this).addClass('moved');
                // Clean up the temporary data
                $(this).removeData('dragged-index');
            }
        });
    }

    function getOriginalMatchesData() {
        const matchesData = {};
        const $firstForm = $("form").first();

        // Get all match indices from the table
        $("#matches tbody tr").each(function() {
            const index = $(this).data('match-index');
            const input0_0 = $firstForm.find("input[name='matches[" + index + "][0][0]']").val();
            const input0_1 = $firstForm.find("input[name='matches[" + index + "][0][1]']").val();
            const input1_0 = $firstForm.find("input[name='matches[" + index + "][1][0]']").val();
            const input1_1 = $firstForm.find("input[name='matches[" + index + "][1][1]']").val();
            const input2 = $firstForm.find("input[name='matches[" + index + "][2]']").val();
            const input3 = $firstForm.find("input[name='matches[" + index + "][3]']").val();

            // Check if all inputs exist
            if (!input0_0 || !input0_1 || !input1_0 || !input1_1 || !input2 || !input3) {
                console.warn('Missing input data for index:', index, {
                    input0_0, input0_1, input1_0, input1_1, input2, input3
                });
            }

            matchesData[index] = [
                [input0_0, input0_1],
                [input1_0, input1_1],
                input2,
                input3
            ];
        });

        return matchesData;
    }
});
