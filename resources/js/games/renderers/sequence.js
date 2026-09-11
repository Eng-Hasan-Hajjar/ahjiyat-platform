export default function sequenceGame(items) {
    return {
        order: items,

        get submissionJson() {
            return JSON.stringify({ order: this.order.map((item) => item.idx) });
        },

        moveUp(position) {
            if (position === 0) return;
            [this.order[position - 1], this.order[position]] = [this.order[position], this.order[position - 1]];
        },

        moveDown(position) {
            if (position === this.order.length - 1) return;
            [this.order[position + 1], this.order[position]] = [this.order[position], this.order[position + 1]];
        },
    };
}   