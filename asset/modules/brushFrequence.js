export class brushFrequence {
    constructor(params) {
        var me = this;
        this.id = params.id ? params.id : 'posiColor';
        this.data = params.data ? params.data : false;
        this.pVal = params.pVal ? params.pVal : 'value';
        this.title = params.title ? params.title : 'Fréquence des valeurs : '+me.pVal;
        this.cont = params.cont ? params.cont : d3.select('body');
        this.svg = params.svg ? params.svg : false;
        this.color = params.color ? params.color : d3.interpolateViridis;
        this.colorSelected = params.colorSelected ? params.colorSelected : "#920b0b";
        this.events = params.events ? params.events : [];
        this.bornes = params.bornes ? params.bornes : [];
        // Specify the chart’s position.
        let svgX=params.x ? params.x : 0, 
            svgY=params.y ? params.y : 0, 
        // Specify the chart’s dimensions.
            width = params.width ? params.width : 600,
            height = params.height ? params.height : 600,
            dataFreq, svg, brush, xScale, xScaleInverted, yScale, bars,
            heightTitle = 15;

        //utiliser pour charger le module depuis javascript externe avec l'objet window
        this.setParams = function (params) {
            me.id = params.id ? params.id : 'brushFrequence';
            me.data = params.data ? params.data : false;
            me.pVal = params.pVal ? params.pVal : 'value';
            me.title = params.title ? params.title : 'Fréquence des valeurs : '+me.pVal;
            me.cont = params.cont ? params.cont : d3.select('body');
            me.svg = params.svg ? params.svg : false;
            me.color = params.color ? params.color : d3.interpolateViridis;
            me.events = params.events ? params.events : [];
            svgX=params.x ? params.x : 0; 
            svgY=params.y ? params.y : 0; 
            // Specify the chart’s dimensions.
            width = params.width ? params.width : 600;
            height = params.height ? params.height : 600;
            me.init();
        }

        this.init = function () {
            if(!me.data) return;
            console.log(me.data);            
            setData();
            setGraph();
        }

        function setData(){
            //définition la fréquence des valeurs
            dataFreq = Array.from(d3.group(me.data, d => d[me.pVal])).map(d=>{
                return {'lib':d[0],'val':d[1].length};
            });
            dataFreq.sort((a,b)=>d3.ascending(a.lib,b.lib));   
        }

        function setGraph(){
                                                          
            //ajoute le titre
            me.cont.append("div").text(me.title);

            // Create the SVG container.
            svg = me.svg ? me.svg : me.cont.append("svg")
                .attr("viewBox", [0, 0, width, height])
                .attr("x", svgX)
                .attr("y", svgY)
                .attr("width", width)
                .attr("height", height)
                .attr("style", "max-width: 100%; height: auto;")
                .style("font", "10px sans-serif");              
                
            brush = d3.brushX()
                .on("start brush end", brushed)
                .on("end.snap", brushended);

            xScale = d3.scalePoint()
                .domain(dataFreq.map(d => d.lib))
                .range([0, width])
                .padding(0.5);
            xScaleInverted = d3.scaleQuantize()
                .domain([0, width])
                .range(dataFreq.map(d => d.lib));
            yScale = d3.scaleLinear()
                .domain(d3.extent(dataFreq, d => d.val))
                .range([10, height]);

            bars = svg.append("g")
                .attr("fill", me.color)
                .selectAll("rect")
                .data(dataFreq)
                .join("rect")
                .attr("x", d =>xScale(d.lib) - xScale.step() / 2)
                .attr("y", d =>0)
                .attr("height", d => yScale(d.val))
                .attr("width", xScale.step());

            svg.append("g")
                .attr("font-family", "var(--sans-serif)")
                .attr("text-anchor", "middle")
                .attr("transform", `translate(${xScale.bandwidth() / 2},${height / 2})`)
                .selectAll("text")
                .data(xScale.domain())
                .join("text")
                .attr("x", d => xScale(d))
                //.attr("y", d =>height/2)
                .attr("dy", "0.35em")
                .text(d => d);

            svg.append("g")
                .attr("class", "brush")
                .attr("height", yScale.range(0))
                .call(brush);

        }
        
        function brushed({selection}) {
            if (selection) {
                const range = xScale.domain().map(xScale);
                const i0 = d3.bisectRight(range, selection[0]);
                const i1 = d3.bisectRight(range, selection[1]);
                bars.attr("fill", (d, i) => i0 <= i && i < i1 ? me.colorSelected : null);
                svg.property("value", xScale.domain().slice(i0, i1)).dispatch("input");
            } else {
                bars.attr("fill", null);
                svg.property("value", []).dispatch("input");
            }
        }

        function brushended({selection, sourceEvent}) {
            if (!sourceEvent || !selection) return;
            const range = xScale.domain().map(xScale), dx = xScale.step() / 2;
            const x0 = range[d3.bisectRight(range, selection[0])] - dx;
            const x1 = range[d3.bisectRight(range, selection[1]) - 1] + dx;
            console.log(xScaleInverted(x0),xScaleInverted(x1));
            me.bornes = [xScaleInverted(x0+10),xScaleInverted(x1-10)];
            //mettre à jour les données affichées en fonction du brush
            //me.cont.dispatch('filterBrushed', {bubbles:bornes, 'detail':{'id':me.id,'bornes':bornes}});
            d3.select(this).transition().call(brush.move, x1 > x0 ? [x0, x1] : null);
            console.log(me.bornes);
            if(me.events && me.events.length>0){
                me.events.forEach(ev=>{
                    if(ev.type==='filterBrushed' && ev.callback){
                        ev.callback(me);
                     }
                });
            }
        }    
        
        this.brushClear = function(){
            svg.select(".brush").call(brush.move, null);
        }

        this.init();    
    }
}