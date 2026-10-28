'use strict';

const matchesGraphSource=(source,query)=>{
  const terms=String(query||'').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
  const text=[
    source.name,source.sub,source.device_name,source.ip,
    source.ifname,source.alias,source.interface_name
  ].filter(Boolean).join(' ').toLocaleLowerCase();
  return terms.every(term=>text.includes(term));
};
const graphSourceSelectionId=source=>
  source.mx80_interface?String(source.mx80_selection_id):String(source.id);

globalThis.NetPulseGraph = Object.freeze({
  ranges: Object.freeze(['hour', 'day', 'week', 'month', 'year']),
  sourceMeta(source){
    return [source.device_name,source.ip,source.interface_name||source.ifname]
      .filter(Boolean).filter((value,index,values)=>values.indexOf(value)===index).join(' · ');
  },
  sourceMatches:matchesGraphSource,
  sourceSelectionId:graphSourceSelectionId,
  pickerOptions(sources,query){
    const options=new Map();
    for(const source of sources.filter(item=>matchesGraphSource(item,query))){
      const key=graphSourceSelectionId(source);
      const option=options.get(key);
      if(!option){
        options.set(key,{key,graphSource:source,labelSource:source});
        continue;
      }
      if(source.mx80_interface){
        if(String(source.id).startsWith('p:'))option.labelSource=source;
        else if(String(option.graphSource.id).startsWith('p:'))option.graphSource=source;
      }
    }
    return [...options.values()];
  },
  formatRate(value){
    if(value==null)return '–';
    value=Number(value);
    if(value>=1e9)return (value/1e9).toFixed(2)+' Gbps';
    if(value>=1e6)return (value/1e6).toFixed(2)+' Mbps';
    if(value>=1e3)return (value/1e3).toFixed(1)+' kbps';
    return Math.round(value)+' bps';
  },
  toggleSelection(selected,id,checked){
    const key=String(id);
    if(checked)return selected.includes(key)?selected:[...selected,key];
    return selected.filter(selectedId=>selectedId!==key);
  },
  clearSelection(){
    return [];
  },
  selectionForSources(selected,sources){
    const available=new Set(sources.map(source=>String(source.id)));
    const valid=selected.filter(id=>available.has(id));
    const fallback=sources.find(source=>!source.mx80_interface);
    return valid.length||!fallback?valid:[String(fallback.id)];
  },
  selectMatching(sources,query){
    return sources.filter(source=>matchesGraphSource(source,query)).map(source=>String(source.id));
  },
  summaryEntries(summary){
    if(!summary)return [];
    return [
      ['Latest Rx',summary.current.rx],['Latest Tx',summary.current.tx],
      ['Avg Rx',summary.average.rx],['Avg Tx',summary.average.tx],
      ['Max Rx',summary.maximum.rx],['Max Tx',summary.maximum.tx]
    ];
  },
  hasHistory(points){
    return Array.isArray(points)&&points.length>=2;
  },
  canFavorite(role){
    return role==='admin'||role==='administrator';
  }
});
