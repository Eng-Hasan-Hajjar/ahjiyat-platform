import Alpine from 'alpinejs';
import sequenceGame from './games/renderers/sequence.js';
import memoryGame from './games/renderers/memory.js';

window.Alpine = Alpine;

Alpine.data('sequenceGame', sequenceGame);
Alpine.data('memoryGame', memoryGame);

Alpine.start();