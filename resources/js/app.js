import Alpine from 'alpinejs';
import sequenceGame from './games/sequence';

window.Alpine = Alpine;

Alpine.data('sequenceGame', sequenceGame);

Alpine.start();