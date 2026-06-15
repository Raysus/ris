-- Fuji FCR Broad Query + carga MWM (error 21054 si falta StudyInstanceUID o la secuencia SPS).
function IncomingWorklistRequestFilter(query, origin)
  query['0008,0020'] = ''
  query['0008,0030'] = ''
  query['0008,0050'] = ''
  query['0010,0010'] = ''
  query['0010,0020'] = ''
  query['0010,0030'] = ''
  query['0010,0040'] = ''
  query['0020,000d'] = ''
  query['0020,0010'] = ''
  query['0032,1060'] = ''
  query['0040,1001'] = ''

  query['0040,0100'] = {
    {
      ['0008,0060'] = '',
      ['0040,0001'] = '',
      ['0040,0002'] = '',
      ['0040,0003'] = '',
      ['0040,0007'] = '',
      ['0040,0009'] = '',
    }
  }

  return query
end
