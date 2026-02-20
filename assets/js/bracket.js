/**
 * Sherwood Adventure Tournament System
 * Bracket Display JavaScript
 *
 * Draws connector lines between match pairs in the bracket tree layout.
 * Uses data-match-number attributes for reliable pairing, even when
 * some matches are hidden (compact mode skips bye matches).
 *
 * Winners bracket: standard 2:1 merge each round.
 *   Match N in round R+1 is fed by matches (N*2-1) and (N*2) from round R.
 *
 * Losers bracket: alternating 1:1 and 2:1 rounds.
 *   Odd→Even (1:1): match N feeds match N  (same count, straight across)
 *   Even→Odd (2:1): matches (N*2-1) and (N*2) merge into match N
 */

(function() {
    'use strict';

    function drawBracketConnectors() {
        var brackets = document.querySelectorAll('.bracket-tree');

        brackets.forEach(function(bracket) {
            // Remove existing drawn lines
            bracket.querySelectorAll('.bracket-vline').forEach(function(v) { v.remove(); });

            var rounds = bracket.querySelectorAll('.bracket-round, .display-bracket-round');
            if (rounds.length < 2) return;

            // We need the bracket to be position:relative for absolute children
            bracket.style.position = 'relative';

            for (var r = 0; r < rounds.length - 1; r++) {
                var currentWrappers = rounds[r].querySelectorAll('.bracket-match-wrapper');
                var nextWrappers = rounds[r + 1].querySelectorAll('.bracket-match-wrapper');

                // Build a lookup of current round wrappers by match number
                var currentByMatchNum = {};
                for (var i = 0; i < currentWrappers.length; i++) {
                    var num = currentWrappers[i].getAttribute('data-match-number');
                    if (num) currentByMatchNum[num] = currentWrappers[i];
                }

                // Determine if this is a 2:1 merge or 1:1 pass-through
                // Count actual matches in each round (visible wrappers)
                var currentCount = currentWrappers.length;
                var nextCount = nextWrappers.length;
                var isMergeRound = (currentCount > nextCount);

                for (var n = 0; n < nextWrappers.length; n++) {
                    var nextMatchNum = parseInt(nextWrappers[n].getAttribute('data-match-number'), 10);
                    if (!nextMatchNum) nextMatchNum = n + 1;

                    // Get the next match's left connector
                    var nextConnector = nextWrappers[n].querySelector('.bracket-connector-left');
                    if (!nextConnector) continue;

                    var bracketRect = bracket.getBoundingClientRect();

                    if (isMergeRound) {
                        // 2:1 merge: two feeder matches combine into one
                        var topFeederNum = String(nextMatchNum * 2 - 1);
                        var botFeederNum = String(nextMatchNum * 2);

                        var topWrapper = currentByMatchNum[topFeederNum] || null;
                        var botWrapper = currentByMatchNum[botFeederNum] || null;

                        if (topWrapper && botWrapper) {
                            // Both feeders visible — draw vertical merge + horizontal bridge
                            var topConn = topWrapper.querySelector('.bracket-connector-right');
                            var botConn = botWrapper.querySelector('.bracket-connector-right');
                            if (!topConn || !botConn) continue;

                            var topRect = topConn.getBoundingClientRect();
                            var botRect = botConn.getBoundingClientRect();
                            var topY = topRect.top + topRect.height / 2 - bracketRect.top;
                            var botY = botRect.top + botRect.height / 2 - bracketRect.top;
                            var lineX = topRect.right - bracketRect.left;

                            // Vertical line
                            var vLine = document.createElement('div');
                            vLine.className = 'bracket-vline';
                            vLine.style.left = (lineX - 1) + 'px';
                            vLine.style.top = topY + 'px';
                            vLine.style.height = (botY - topY) + 'px';
                            bracket.appendChild(vLine);

                            // Horizontal bridge from midpoint to next match
                            var midY = (topY + botY) / 2;
                            var nextRect = nextConnector.getBoundingClientRect();
                            var nextX = nextRect.left - bracketRect.left;

                            var hLine = document.createElement('div');
                            hLine.className = 'bracket-vline';
                            hLine.style.left = lineX + 'px';
                            hLine.style.top = (midY - 1) + 'px';
                            hLine.style.width = (nextX - lineX) + 'px';
                            hLine.style.height = '2px';
                            bracket.appendChild(hLine);
                        } else if (topWrapper || botWrapper) {
                            // Only one feeder visible (compact mode hid the other)
                            var visWrapper = topWrapper || botWrapper;
                            var visConn = visWrapper.querySelector('.bracket-connector-right');
                            if (!visConn) continue;

                            var visRect = visConn.getBoundingClientRect();
                            var visY = visRect.top + visRect.height / 2 - bracketRect.top;
                            var startX = visRect.right - bracketRect.left;
                            var nextRect = nextConnector.getBoundingClientRect();
                            var nextX = nextRect.left - bracketRect.left;

                            var hLine = document.createElement('div');
                            hLine.className = 'bracket-vline';
                            hLine.style.left = startX + 'px';
                            hLine.style.top = (visY - 1) + 'px';
                            hLine.style.width = (nextX - startX) + 'px';
                            hLine.style.height = '2px';
                            bracket.appendChild(hLine);
                        }
                    } else {
                        // 1:1 pass-through: same match number feeds straight across
                        var feederNum = String(nextMatchNum);
                        var feederWrapper = currentByMatchNum[feederNum] || null;

                        if (feederWrapper) {
                            var feederConn = feederWrapper.querySelector('.bracket-connector-right');
                            if (!feederConn) continue;

                            var feederRect = feederConn.getBoundingClientRect();
                            var feederY = feederRect.top + feederRect.height / 2 - bracketRect.top;
                            var startX = feederRect.right - bracketRect.left;
                            var nextRect = nextConnector.getBoundingClientRect();
                            var nextX = nextRect.left - bracketRect.left;

                            var hLine = document.createElement('div');
                            hLine.className = 'bracket-vline';
                            hLine.style.left = startX + 'px';
                            hLine.style.top = (feederY - 1) + 'px';
                            hLine.style.width = (nextX - startX) + 'px';
                            hLine.style.height = '2px';
                            bracket.appendChild(hLine);
                        }
                    }
                }
            }
        });
    }

    // Draw on load and resize
    if (document.querySelector('.bracket-tree')) {
        window.addEventListener('load', drawBracketConnectors);
        window.addEventListener('resize', function() {
            clearTimeout(window._bracketResizeTimer);
            window._bracketResizeTimer = setTimeout(drawBracketConnectors, 200);
        });
    }

})();
