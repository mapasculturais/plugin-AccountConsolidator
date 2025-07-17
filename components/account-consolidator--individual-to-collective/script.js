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
        return {agents};
    },
    // define os eventos que este componente emite    
    methods: {
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
