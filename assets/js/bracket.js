/**
 * Sherwood Adventure Tournament System
 * Bracket Display JavaScript
 *
 * Handles visual bracket connector lines using CSS/SVG
 */

(function() {
    'use strict';

    // ============================================================
    // BRACKET CONNECTOR LINES
    // ============================================================
    function drawBracketConnectors() {
        var brackets = document.querySelectorAll('.bracket');

        brackets.forEach(function(bracket) {
            // Remove existing connectors
            bracket.querySelectorAll('.bracket-connector').forEach(function(c) { c.remove(); });

            var rounds = bracket.querySelectorAll('.bracket-round');

            for (var r = 0; r < rounds.length - 1; r++) {
                var currentMatches = rounds[r].querySelectorAll('.bracket-match');
                var nextMatches = rounds[r + 1].querySelectorAll('.bracket-match');

                for (var m = 0; m < nextMatches.length; m++) {
                    var match1Index = m * 2;
                    var match2Index = m * 2 + 1;

                    if (currentMatches[match1Index] && nextMatches[m]) {
                        drawConnector(bracket, currentMatches[match1Index], nextMatches[m]);
                    }
                    if (currentMatches[match2Index] && nextMatches[m]) {
                        drawConnector(bracket, currentMatches[match2Index], nextMatches[m]);
                    }
                }
            }
        });
    }

    function drawConnector(bracket, fromMatch, toMatch) {
        var bracketRect = bracket.getBoundingClientRect();
        var fromRect = fromMatch.getBoundingClientRect();
        var toRect = toMatch.getBoundingClientRect();

        var connector = document.createElement('div');
        connector.className = 'bracket-connector';
        connector.style.cssText = 'position: absolute; pointer-events: none;';

        var fromX = fromRect.right - bracketRect.left;
        var fromY = fromRect.top + fromRect.height / 2 - bracketRect.top;
        var toX = toRect.left - bracketRect.left;
        var toY = toRect.top + toRect.height / 2 - bracketRect.top;
        var midX = (fromX + toX) / 2;

        // Draw L-shaped connector using border
        var hLine1 = document.createElement('div');
        hLine1.style.cssText = 'position: absolute; height: 1px; background: rgba(70, 36, 12, 0.5);' +
            'left: ' + fromX + 'px; top: ' + fromY + 'px; width: ' + (midX - fromX) + 'px;';

        var vLine = document.createElement('div');
        var vTop = Math.min(fromY, toY);
        var vHeight = Math.abs(toY - fromY);
        vLine.style.cssText = 'position: absolute; width: 1px; background: rgba(70, 36, 12, 0.5);' +
            'left: ' + midX + 'px; top: ' + vTop + 'px; height: ' + vHeight + 'px;';

        var hLine2 = document.createElement('div');
        hLine2.style.cssText = 'position: absolute; height: 1px; background: rgba(70, 36, 12, 0.5);' +
            'left: ' + midX + 'px; top: ' + toY + 'px; width: ' + (toX - midX) + 'px;';

        bracket.style.position = 'relative';
        bracket.appendChild(hLine1);
        bracket.appendChild(vLine);
        bracket.appendChild(hLine2);
    }

    // Draw on load and resize
    if (document.querySelector('.bracket')) {
        window.addEventListener('load', drawBracketConnectors);
        window.addEventListener('resize', function() {
            // Debounce
            clearTimeout(window._bracketResizeTimer);
            window._bracketResizeTimer = setTimeout(drawBracketConnectors, 200);
        });
    }

})();
