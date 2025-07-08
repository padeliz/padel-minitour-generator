$(document).ready(function () {
    // Store original matches data once at the beginning
    const originalMatchesData = getOriginalMatchesData();
    let userHasInteracted = false;
    let allIntervals = []; // Global list to track all intervals and timeouts

    $("#players tr").hover(
        // mouseenter
        function (event) {
            const playerName = $(this).find("[data-player-name]").text();
            const $playerCell = $(this).find("[data-player-name]");
            const $matchCells = $("#matches [data-player-name='"+playerName+"']");

            // Only mark user interaction if it's a real user event (not programmatic)
            if (!event.isTrigger) {
                userHasInteracted = true;
            }

            // Get the player's distribution class and apply corresponding hover class
            const $distributionCell = $(".distribution-index[data-player='" + playerName + "']");
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
            const playerName = $(this).find("[data-player-name]").text();
            const $playerCell = $(this).find("[data-player-name]");
            const $matchCells = $("#matches [data-player-name='"+playerName+"']");

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

                $(`#players tbody tr:has([data-player-name])`).trigger('mouseleave');
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

        // Add delay to make loading animation visible
        const calculationTimeout = setTimeout(() => {
            // Get current match order
            const currentMatches = [];
            $("#matches tbody tr").each(function(index) {
                const originalIndex = $(this).data('match-index');
                currentMatches.push({
                    position: index,
                    originalIndex: originalIndex,
                    data: originalMatchesData[originalIndex]
                });
            });

            // Calculate distribution for each player
            const players = [];
            $("#players tbody tr").each(function() {
                const playerName = $(this).find("[data-player-name]").text();
                players.push(playerName);
            });

            // Display results one by one with staggered delay
            players.forEach(function(player, index) {
                const playerTimeout = setTimeout(() => {
                    const distributionScore = calculatePlayerDistribution(player, currentMatches);

                    updatePlayerDistributionIndex(player, distributionScore);

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
            const playerName = $cell.data('player');
            const score = parseFloat($cell.text()) / 100; // Convert percentage back to decimal

            if (score < worstScore) {
                worstScore = score;
                worstPlayer = playerName;
            }
        });

        if (worstPlayer) {
            const $worstPlayerRow = $(`#players tbody tr:has([data-player-name="${worstPlayer}"])`);

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

    function calculatePlayerDistribution(player, matches) {
        // Find all matches where this player participates
        const playerMatches = [];
        matches.forEach(function(match, index) {
            const matchData = match.data;

            if (matchData[0][0] === player || matchData[0][1] === player ||
                matchData[1][0] === player || matchData[1][1] === player) {
                playerMatches.push(index);
            }
        });

        if (playerMatches.length <= 1) {
            return 1; // Perfect distribution if only one match
        }

        const totalMatches = matches.length;
        const matchCount = playerMatches.length;

        // Calculate ideal distribution
        const idealGap = totalMatches / matchCount;

        // Calculate actual gaps between consecutive matches
        const gaps = [];
        for (const [i, match] of playerMatches.entries()) {
            if (i > 0) {
                gaps.push(match - playerMatches[i-1]);
            }
        }

        // Add gaps at the beginning and end of the schedule
        const firstMatch = playerMatches[0];
        const lastMatch = playerMatches[playerMatches.length - 1];

        // Gap from start of schedule to first match
        if (firstMatch > 0) {
            gaps.unshift(firstMatch);
        }

        // Gap from last match to end of schedule
        if (lastMatch < totalMatches - 1) {
            gaps.push(totalMatches - 1 - lastMatch);
        }

        // Calculate gap variance (how consistent the gaps are)
        const avgGap = gaps.reduce((sum, gap) => sum + gap, 0) / gaps.length;
        const gapVariance = gaps.reduce((sum, gap) => sum + Math.pow(gap - avgGap, 2), 0) / gaps.length;
        const normalizedGapVariance = gapVariance / Math.pow(totalMatches, 2);

        // Calculate clustering penalty (penalize consecutive and close matches)
        let clusteringPenalty = 0;
        let largeGapPenalty = 0;

        // Dynamic penalty based on match density
        const matchDensity = matchCount / totalMatches; // How many matches this player has relative to total
        const basePenalty = Math.max(0.1, 0.5 - (matchDensity * 0.3)); // Lower penalty for high-density players

        // Define acceptable range around ideal gap (no penalty within this range)
        const acceptableRange = idealGap * 0.3; // 30% tolerance around ideal gap
        const minAcceptableGap = idealGap - acceptableRange;
        const maxAcceptableGap = idealGap + acceptableRange;

        for (const gap of gaps) {
            if (gap >= minAcceptableGap && gap <= maxAcceptableGap) {
                // No penalty for gaps close to ideal
            } else if (gap < minAcceptableGap) {
                // Penalty for gaps smaller than ideal (clustering)
                const gapDeficit = minAcceptableGap - gap;
                const penaltyRatio = gapDeficit / idealGap;
                clusteringPenalty += Math.min(basePenalty, penaltyRatio * basePenalty);
            }

            // Calculate large gap penalty (penalize gaps that are too large)
            if (gap > maxAcceptableGap) {
                // Penalty increases with the size of the gap
                const gapExcess = gap - maxAcceptableGap;
                const penaltyRatio = gapExcess / maxAcceptableGap;
                largeGapPenalty += Math.min(0.6, penaltyRatio * 0.6); // Increased cap and multiplier
            }
        }

        // Calculate overall spread (how well distributed across the entire schedule)
        const totalSpan = lastMatch - firstMatch;
        const idealSpan = (matchCount - 1) * idealGap;
        const spanRatio = Math.min(totalSpan / idealSpan, 1); // Should be close to 1

        // Calculate distribution score
        const gapScore = 1 - normalizedGapVariance;
        const clusteringScore = Math.max(0, 1 - clusteringPenalty);
        const largeGapScore = Math.max(0, 1 - largeGapPenalty);

        // Weight the different factors
        const finalScore = (gapScore * 0.3) + (clusteringScore * 0.3) + (largeGapScore * 0.3) + (spanRatio * 0.1);

        return Math.max(0, Math.min(1, finalScore));
    }

    function updatePlayerDistributionIndex(player, score) {
        const $cell = $(".distribution-index[data-player='" + player + "']");
        const percentage = Math.round(score * 100);
        let colorClass;

        if (score >= 0.9) {
            colorClass = "excellent";
        } else if (score >= 0.8) {
            colorClass = "good";
        } else if (score >= 0.6) {
            colorClass = "fair";
        } else {
            colorClass = "poor";
        }

        $cell.removeClass('excellent good fair poor').addClass(colorClass);
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
