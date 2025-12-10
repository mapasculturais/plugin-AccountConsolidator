/**
 * Vue Lifecycle
 * 1. setup
 * 2. beforeCreate
 * 3. created
 * 4. beforeMount
 * 5. mounted
 * 
 * // sempre que há modificação nos dados
 *  - beforeUpdate
 *  - updated
 * 
 * 6. beforeUnmount
 * 7. unmounted                  
 */

app.component('account-consolidator--individual-to-collective', {
    template: $TEMPLATES['account-consolidator--individual-to-collective'],
    
    data() {
        const agents = $MAPAS.config['account-consolidator--individual-to-collective'].agents;
        agents.forEach(element => {
            element.checked = true;
        });
        return {agents, uncheckedLoading: false, savingState: false};
    },
    async mounted() {
        this.uncheckedLoading = true;
        try {
            const url = Utils.createUrl('account-consolidator', 'loadCheckboxState');
            const res = await fetch(url);
            const data = await res.json();
            const unchecked = Array.isArray(data.unchecked) ? data.unchecked : [];
            this.agents.forEach(a => {
                if (unchecked.includes(a.id)) {
                    a.checked = false;
                }
            });
        } catch (e) {
            console.warn('Falha ao carregar estado de checkboxes', e);
        } finally {
            this.uncheckedLoading = false;
        }
    },
    methods: {
        async check(agent, $event) {
            const checked = $event.target.checked;
            const url = Utils.createUrl('account-consolidator', 'saveCheckboxState');
            this.savingState = true;
            try {
                await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({agentId: agent.id, checked: checked}),
                });
            } catch (e) {
                console.warn('Falha ao salvar estado de checkbox', e);
            } finally {
                this.savingState = false;
            }
        },
        post($event) {
            const agents = this.agents.filter((a) => a.checked);
            const data = {agents: agents.map((a) => a.id)};

            const headers = {'Content-Type': 'application/json'};
            const url = Utils.createUrl('account-consolidator', 'enqueueJob');

            return fetch(url, {
                method: 'POST',
                headers,
                body: JSON.stringify(data)

            }).catch((e) => {
                return new Response(null, {status: 0, statusText: 'erro inesperado'});
            });
            
            debugger

        },

        id(agent) {
            return 'agent-' + agent.id;
        }
    },
});
