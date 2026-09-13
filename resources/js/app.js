import Alpine from 'alpinejs';
import sequenceGame from './games/renderers/sequence.js';
import memoryGame from './games/renderers/memory.js';
import spotDifferenceGame from './games/renderers/spot-difference.js';

window.Alpine = Alpine;

Alpine.data('sequenceGame', sequenceGame);
Alpine.data('memoryGame', memoryGame);
Alpine.data('spotDifferenceGame', spotDifferenceGame);

Alpine.start();